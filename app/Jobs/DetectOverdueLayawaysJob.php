<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Layaway\DetectOverdueAction;
use App\Domain\Layaway\OverdueSummary;
use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Detecta la mora a las 06:00 de cada organización (`REQ-LAY-007` RB-5,
 * `REQ-INF-003` RB-5 ⏰).
 *
 * El scheduler lo despacha **cada hora**, y en cada pasada solo visita las
 * organizaciones cuya hora local es la de la regla: con negocios en husos
 * distintos, una sola ejecución diaria a hora fija evaluaría a parte de ellos
 * con el «hoy» del día anterior (SP-6, `REQ-INF-003` RB-5b). La idempotencia
 * de `DetectOverdueAction` hace inofensivo que una organización se visite dos
 * veces, y un día perdido se recupera solo al siguiente (SP-5).
 *
 * Recorre también las suspendidas (RB-8), con el tenant fijado y limpiado por
 * organización (`RunsForEveryOrganization`).
 */
class DetectOverdueLayawaysJob implements ShouldQueue
{
    use Queueable;
    use RunsForEveryOrganization;

    /** RB-5 · la hora local a la que se evalúa cada organización. */
    public const LOCAL_HOUR = 6;

    /** `REQ-INF-003` RB-4. */
    public int $tries = 3;

    /**
     * El instante de la ejecución, fijado al empezar: decide qué
     * organizaciones están a su hora y qué día es «hoy» para cada una.
     */
    private ?CarbonImmutable $now = null;

    public function __construct()
    {
        // `REQ-INF-003` RB-1: la detección de mora va en `default`.
        $this->onQueue('default');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('layaways-detect-overdue'))->dontRelease()->expireAfter(1800)];
    }

    public function handle(DetectOverdueAction $detect): void
    {
        $this->now = CarbonImmutable::now();
        $now = $this->now;
        $total = new OverdueSummary;

        $this->forEachOrganization(function (Organization $organization) use ($detect, $now, &$total): void {
            $total = $total->plus($detect->handle($now));
        });

        Log::info('layaways.overdue_detected', $total->toArray());
    }

    /**
     * Solo las organizaciones para las que ahora son las 06:00.
     *
     * @return iterable<int, Organization>
     */
    protected function organizationsToVisit(): iterable
    {
        $now = $this->now ?? CarbonImmutable::now();

        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
            if ($now->setTimezone($organization->timezone)->hour === self::LOCAL_HOUR) {
                yield $organization;
            }
        }
    }
}
