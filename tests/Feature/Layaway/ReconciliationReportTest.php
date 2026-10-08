<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Domain\Layaway\ReconciliationReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Lo que encontró una pasada de la reconciliación y la señal que deja para
 * la pantalla de Salud (`REQ-PAY-008` RB-4, `REQ-PLT-006` RB-2, `T-064`).
 */
final class ReconciliationReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(ReconciliationReport::CACHE_KEY);
    }

    public function test_it_counts_what_it_checked_and_what_it_did_with_each_discrepancy(): void
    {
        $first = new ReconciliationReport;
        $first->layaways = 3;
        $first->add(['type' => 'layaway', 'outcome' => 'fixed']);
        $first->add(['type' => 'product', 'outcome' => 'skipped']);

        $second = new ReconciliationReport;
        $second->layaways = 2;
        $second->products = 1;
        $second->add(['type' => 'layaway', 'outcome' => 'unresolved']);

        $first->merge($second);

        $this->assertSame([5, 1, 3, 2], [$first->layaways, $first->products, $first->discrepancies(), $first->balanceDiscrepancies()]);
        $this->assertSame([1, 1, 1, 0], [$first->count('fixed'), $first->count('skipped'), $first->count('unresolved'), $first->count('failed')]);
        $this->assertSame(['layaways' => 5, 'products' => 1, 'discrepancies' => 3, 'fixed' => 1, 'skipped' => 1, 'unresolved' => 1], array_diff_key($first->toArray(), ['entries' => true]));
    }

    /**
     * La señal separa los saldos —lo que `REQ-PLT-006` RB-2 llama
     * descuadres— de los faltantes de existencias.
     */
    public function test_the_last_pass_is_remembered_with_balances_and_stock_apart(): void
    {
        $this->assertNull(ReconciliationReport::last());

        $report = new ReconciliationReport;
        $report->add(['type' => 'layaway', 'outcome' => 'reported']);
        $report->add(['type' => 'layaway', 'outcome' => 'unresolved']);
        $report->add(['type' => 'product', 'outcome' => 'reported']);
        $report->remember(CarbonImmutable::parse('2026-10-08 05:30:00'));

        $this->assertSame(
            ['discrepancies' => 2, 'stock' => 1, 'unresolved' => 1, 'lastRunAt' => CarbonImmutable::parse('2026-10-08 05:30:00')->toIso8601String()],
            ReconciliationReport::last(),
        );
    }

    /**
     * Una entrada de caché que no tiene la forma —otra versión, o algo que
     * escribió otro— se lee como «sin pasada conocida», no como cero.
     */
    public function test_a_malformed_cache_entry_reads_as_no_known_pass(): void
    {
        foreach (['texto', ['discrepancies' => 1], ['discrepancies' => 1, 'stock' => 0, 'unresolved' => 0]] as $value) {
            Cache::forever(ReconciliationReport::CACHE_KEY, $value);

            $this->assertNull(ReconciliationReport::last());
        }
    }
}
