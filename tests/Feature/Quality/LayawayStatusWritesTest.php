<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

const LAYAWAY_STATE_MACHINE = 'app/Domain/Layaway/LayawayStateMachine.php';

/**
 * @return list<string>
 */
function layawayStatusWritesIn(string $code): array
{
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

    expect($offenders)->toBe([], "El estado de un apartado se escribe fuera de LayawayStateMachine:\n"
        .implode("\n", $offenders)
        ."\nUsa LayawayStateMachine::transition(): valida la matriz, escribe la marca, ajusta el cupo y emite el evento.");
});

it('reconoce la escritura de la máquina, que es la única admitida', function (): void {
    expect(layawayStatusWritesIn((string) file_get_contents(base_path(LAYAWAY_STATE_MACHINE))))->not->toBe([]);
});

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
