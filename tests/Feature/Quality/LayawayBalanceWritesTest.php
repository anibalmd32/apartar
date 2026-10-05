<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `REQ-PAY-008` RB-7: **ninguna otra clase** escribe el balance materializado
 * de un apartado. Los cuatro campos, y quién los escribe:
 *
 *  - `paid_cents`, `remaining_cents` y `next_due_date`: **solo
 *    `LayawayBalanceRecalculator`**. Es lo que comprueba este archivo.
 *  - `status`: lo **decide** el recalculador cuando lo mueve el dinero
 *    —completar con la tolerancia, entrar y salir de la mora— y lo **escribe**
 *    `LayawayStateMachine`, que es la única escritura del estado que admite
 *    `LayawayStatusWritesTest` (`REQ-LAY-005` RB-2). Aquí se afirma que el
 *    recalculador pide la transición y no la escribe.
 *
 * Es la misma forma de dos capas que el estado. **La primera es el modelo**:
 * `Layaway` lanza si se guarda un apartado existente con cualquiera de
 * `BALANCE_COLUMNS` cambiada, y si se crea con un saldo que no sea «sin
 * pagos» (`paid_cents = 0`, `remaining_cents = total_cents`, sin
 * vencimiento). Así se admiten los escritores legítimos del `INSERT`
 * —`CreateLayawayAction` y los sembradores de test, `CreatesLayaways` y
 * `LayawayFactory`— **por la regla y no por una lista**: siembran el balance
 * sin pagos, y ninguno puede sembrar otro. Lo afirma
 * `LayawayBalanceRecalculatorTest`.
 *
 * **La segunda es esto**: lo que no pasa por un modelo —un `update()` del
 * *query builder*, un `DB::table('layaways')`, un `increment()`, SQL a mano—
 * no dispara eventos de Eloquent. Se recorren `app/` y `database/`, se busca
 * en los archivos que hablan de apartados, y se admite **un único archivo**.
 *
 * `paid_cents` lo comparte `installments`, así que un `update()` del *query
 * builder* sobre cuotas en un archivo que nombra apartados sale aquí como
 * falso positivo. Se arregla escribiendo la cuota por su modelo
 * (`forceFill()->save()`), que además pasa por la guarda de inmutabilidad de
 * `Installment`: un falso positivo cuesta mover una línea; un falso negativo
 * es un saldo que nadie sabe de dónde salió.
 */
const LAYAWAY_BALANCE_RECALCULATOR = 'app/Domain/Layaway/LayawayBalanceRecalculator.php';

/**
 * Las sentencias de ese código que escriben el balance de un apartado por
 * fuera de un modelo.
 *
 * @return list<string>
 */
function layawayBalanceWritesIn(string $code): array
{
    // Sin comentarios —la documentación nombra estas formas— y con literales.
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

    $column = '(paid_cents|remaining_cents|next_due_date)';
    $offenders = [];

    foreach (explode(';', $stripped) as $statement) {
        $writesThroughBuilder = preg_match('/->\s*(update|upsert|insert|insertOrIgnore|insertGetId|updateOrInsert|incrementEach|decrementEach)\s*\(/', $statement) === 1
            && preg_match('/[\'"]'.$column.'[\'"]\s*=>/', $statement) === 1;

        $writesByIncrement = preg_match('/->\s*(increment|decrement)\s*\(\s*[\'"]'.$column.'[\'"]/', $statement) === 1;

        $writesRawSql = preg_match('/update\s+`?layaways`?\s+set\b[^;]*\b'.$column.'\b/i', $statement) === 1;

        if ($writesThroughBuilder || $writesByIncrement || $writesRawSql) {
            $offenders[] = trim((string) preg_replace('/\s+/', ' ', $statement));
        }
    }

    return $offenders;
}

it('solo deja escribir el balance del apartado al recalculador', function (): void {
    $offenders = [];

    $root = (string) realpath(base_path());

    foreach (Finder::create()->files()->in([base_path('app'), base_path('database')])->name('*.php') as $file) {
        $relative = str_replace('\\', '/', substr((string) $file->getRealPath(), strlen($root) + 1));

        if ($relative === LAYAWAY_BALANCE_RECALCULATOR) {
            continue;
        }

        foreach (layawayBalanceWritesIn((string) file_get_contents($file->getRealPath())) as $statement) {
            $offenders[] = "{$relative}: {$statement}";
        }
    }

    expect($offenders)->toBe([], "El balance de un apartado se escribe fuera de LayawayBalanceRecalculator (REQ-PAY-008 RB-7):\n"
        .implode("\n", $offenders)
        ."\nMueve los pagos o las cuotas y llama a LayawayBalanceRecalculator::recalculate(). Si es una cuota, escríbela por su modelo.");
});

/**
 * El recalculador sí escribe, por el *query builder*: si dejara de hacerlo, la
 * excepción de arriba estaría admitiendo un archivo que ya no la necesita.
 */
it('reconoce la escritura del recalculador, que es la única admitida', function (): void {
    expect(layawayBalanceWritesIn((string) file_get_contents(base_path(LAYAWAY_BALANCE_RECALCULATOR))))->not->toBe([]);
});

/**
 * El cuarto campo: el recalculador pide la transición a la máquina y no
 * escribe el estado. `LayawayStatusWritesTest` ya rechaza la escritura en
 * cualquier archivo; esto afirma además que la decisión está aquí.
 */
it('decide el estado pidiéndoselo a la máquina, sin escribirlo', function (): void {
    $code = (string) file_get_contents(base_path(LAYAWAY_BALANCE_RECALCULATOR));

    expect($code)->toContain('$this->machine->transition(');
    expect($code)->not->toMatch('/[\'"]status[\'"]\s*=>/');
});

/**
 * Y el detector detecta: un test que busca sobre un conjunto vacío pasa
 * también cuando está roto.
 */
it('caza las formas de escribir el balance por fuera de un modelo', function (string $code): void {
    expect(layawayBalanceWritesIn("<?php\n".$code))->not->toBe([]);
})->with([
    'query builder' => ["Layaway::query()->whereKey(1)->update(['paid_cents' => 5000]);"],
    'DB::table' => ["DB::table('layaways')->where('id', 1)->update(['remaining_cents' => 0]);"],
    'SQL a mano' => ["DB::update('update layaways set next_due_date = ? where id = ?', ['2026-09-25', 1]);"],
    'increment' => ["Layaway::query()->whereKey(1)->increment('paid_cents', 3000);"],
    'decrementEach' => ["DB::table('layaways')->where('id', 1)->decrementEach(['remaining_cents' => 3000]);"],
    'upsert' => ["DB::table('layaways')->upsert([['id' => 1, 'next_due_date' => null]], ['id']);"],
]);

it('no confunde lecturas, otras columnas ni la escritura por un modelo', function (string $code): void {
    expect(layawayBalanceWritesIn("<?php\n".$code))->toBe([]);
})->with([
    'otra columna' => ["Layaway::query()->whereKey(1)->update(['extended_at' => now()]);"],
    'una lectura' => ["Layaway::query()->where('remaining_cents', '>', 0)->get();"],
    'un filtro antes de otra columna' => ["Layaway::query()->where('remaining_cents', 0)->update(['delivered_at' => now()]);"],
    'un agregado' => ["\$sum = Layaway::query()->sum('paid_cents');"],
    'la cuota por su modelo' => ["\$installment->forceFill(['paid_cents' => \$paid])->save(); \$layaway = new Layaway;"],
    'un archivo sin apartados' => ["Installment::query()->whereKey(1)->update(['paid_cents' => 1]);"],
]);
