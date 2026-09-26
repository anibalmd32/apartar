<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `REQ-LAY-005` RB-2: **ninguna** escritura del estado de un apartado ocurre
 * fuera de `LayawayStateMachine`.
 *
 * Es la segunda capa del mecanismo. La primera es la guarda del modelo
 * `Layaway`, que lanza al guardar un estado cambiado; pero lo que no pasa por
 * un modelo —un `update()` del *query builder*, un `DB::table('layaways')`, una
 * sentencia SQL escrita a mano— no dispara eventos de Eloquent, y la guarda no
 * lo ve. Esto recorre `app/` y lo busca.
 *
 * Es una comprobación de texto y no pretende ser un analizador: busca las
 * formas en que se escribe una columna por fuera de un modelo, en los archivos
 * que hablan de apartados, y admite **un único archivo**, la máquina. Un falso
 * positivo cuesta mover una línea; un falso negativo es un `completed` con
 * cuotas pendientes que nadie sabe de dónde salió.
 */
const LAYAWAY_STATE_MACHINE = 'app/Domain/Layaway/LayawayStateMachine.php';

/**
 * Las sentencias de ese código que escriben el estado de un apartado por
 * fuera de un modelo.
 *
 * @return list<string>
 */
function layawayStatusWritesIn(string $code): array
{
    // Sin comentarios —la documentación nombra estas formas para explicarlas—
    // pero **con** literales de cadena, que es donde viven la columna y el SQL.
    $stripped = '';

    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    if (! preg_match('/Layaway\b|[\'"]layaways[\'"]|\blayaways\b/', $stripped)) {
        return [];
    }

    $offenders = [];

    foreach (explode(';', $stripped) as $statement) {
        $writesThroughBuilder = preg_match('/->\s*(update|upsert|insert|insertOrIgnore|updateOrInsert)\s*\(/', $statement) === 1
            && preg_match('/[\'"]status[\'"]\s*=>/', $statement) === 1;

        $writesRawSql = preg_match('/update\s+`?layaways`?\s+set\b[^;]*\bstatus\b/i', $statement) === 1;

        if ($writesThroughBuilder || $writesRawSql) {
            $offenders[] = trim((string) preg_replace('/\s+/', ' ', $statement));
        }
    }

    return $offenders;
}

it('solo deja escribir el estado del apartado a la máquina', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        if ($relative === LAYAWAY_STATE_MACHINE) {
            continue;
        }

        foreach (layawayStatusWritesIn((string) file_get_contents($file->getRealPath())) as $statement) {
            $offenders[] = "{$relative}: {$statement}";
        }
    }

    expect($offenders)->toBe([], "El estado de un apartado se escribe fuera de LayawayStateMachine (REQ-LAY-005 RB-2):\n"
        .implode("\n", $offenders)
        ."\nUsa LayawayStateMachine::transition(): valida la matriz, escribe la marca, ajusta el cupo y emite el evento.");
});

/**
 * La máquina sí escribe, y por el *query builder*: si dejara de hacerlo, la
 * excepción de arriba estaría admitiendo un archivo que ya no la necesita.
 */
it('reconoce la escritura de la máquina, que es la única admitida', function (): void {
    expect(layawayStatusWritesIn((string) file_get_contents(base_path(LAYAWAY_STATE_MACHINE))))->not->toBe([]);
});

/**
 * Y el detector detecta: un test que busca sobre un conjunto vacío pasa
 * también cuando está roto.
 */
it('caza las formas de escribir el estado por fuera de un modelo', function (string $code): void {
    expect(layawayStatusWritesIn("<?php\n".$code))->not->toBe([]);
})->with([
    'query builder' => ["Layaway::query()->whereKey(1)->update(['status' => 'completed']);"],
    'DB::table' => ["DB::table('layaways')->where('id', 1)->update(['status' => 'active', 'activated_at' => now()]);"],
    'SQL a mano' => ["DB::update('update layaways set status = ? where id = ?', ['expired', 1]);"],
    'updateOrInsert' => ["DB::table('layaways')->updateOrInsert(['id' => 1], ['status' => 'cancelled']);"],
]);

it('no confunde el estado de otra tabla ni el cast del modelo', function (string $code): void {
    expect(layawayStatusWritesIn("<?php\n".$code))->toBe([]);
})->with([
    'otra tabla' => ["Product::query()->whereKey(1)->update(['status' => 'archived']);"],
    'el cast' => ["class Layaway { protected function casts(): array { return ['status' => LayawayStatus::class]; } }"],
    'otra columna' => ["Layaway::query()->whereKey(1)->update(['extended_at' => now()]);"],
]);
