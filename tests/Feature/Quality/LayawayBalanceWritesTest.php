<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

const LAYAWAY_BALANCE_RECALCULATOR = 'app/Domain/Layaway/LayawayBalanceRecalculator.php';

/**
 * @return list<string>
 */
function layawayBalanceWritesIn(string $code): array
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

    expect($offenders)->toBe([], "El balance de un apartado se escribe fuera de LayawayBalanceRecalculator:\n"
        .implode("\n", $offenders)
        ."\nMueve los pagos o las cuotas y llama a LayawayBalanceRecalculator::recalculate(). Si es una cuota, escríbela por su modelo.");
});

it('reconoce la escritura del recalculador, que es la única admitida', function (): void {
    expect(layawayBalanceWritesIn((string) file_get_contents(base_path(LAYAWAY_BALANCE_RECALCULATOR))))->not->toBe([]);
});

it('decide el estado pidiéndoselo a la máquina, sin escribirlo', function (): void {
    $code = (string) file_get_contents(base_path(LAYAWAY_BALANCE_RECALCULATOR));

    expect($code)->toContain('$this->machine->transition(');
    expect($code)->not->toMatch('/[\'"]status[\'"]\s*=>/');
});

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
