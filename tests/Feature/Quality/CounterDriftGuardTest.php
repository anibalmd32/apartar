<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * La vigilancia de `FailsOnCounterDrift` no está ciega (`T-045`).
 *
 * Toda la suite pasa por `assertNoCounterDrift()`, y una vigilancia que no
 * viera nada pasaría en verde igual. Esto registra la anomalía a mano, afirma
 * que se vio, y la retira para que el propio test no falle por ella.
 */
final class CounterDriftGuardTest extends TestCase
{
    public function test_a_below_zero_warning_is_caught(): void
    {
        Log::warning('usage_counter.below_zero', ['metric' => 'active_layaways', 'previous' => 0, 'delta' => -1]);

        $this->assertCount(1, $this->counterDrift);
        $this->assertSame('active_layaways', $this->counterDrift[0]['metric']);

        $this->counterDrift = [];
    }

    /**
     * `T-057` · un saldo acotado a cero por exceso también es deriva.
     */
    public function test_an_overpaid_layaway_is_caught(): void
    {
        Log::warning('layaway_balance.overpaid', ['layaway_id' => 7, 'overpaid_cents' => 100]);

        $this->assertCount(1, $this->counterDrift);
        $this->assertSame('layaway_balance.overpaid', $this->counterDrift[0]['message']);
        $this->assertSame(100, $this->counterDrift[0]['overpaid_cents']);

        $this->counterDrift = [];
    }

    public function test_other_warnings_are_not_drift(): void
    {
        Log::warning('usage_counter.recomputed', ['metric' => 'active_layaways']);

        $this->assertSame([], $this->counterDrift);
    }
}
