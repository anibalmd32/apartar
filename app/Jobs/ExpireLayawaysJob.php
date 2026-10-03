<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Layaway\ExpireLayawaysAction;
use App\Domain\Layaway\ExpirationOutcome;
use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Expira cada hora los apartados cuya inicial no llegó (`REQ-LAY-006` RB-6).
 *
 * Recorre **todas** las organizaciones —también las suspendidas, RB-8— con
 * `RunsForEveryOrganization`, que fija el tenant por organización y lo limpia
 * al salir de cada una (SP-7). El trabajo de cada una es de
 * `ExpireLayawaysAction`; aquí solo se recorre, con un único instante para
 * toda la ejecución, y se deja constancia de lo que pasó.
 *
 * Es idempotente: un apartado ya expirado no vuelve a entrar en el filtro, y
 * la liberación de stock lo es por `stock_released_at` (RB-4). Por eso un
 * fallo a mitad no se compensa a mano: la pasada siguiente recoge lo que
 * quedó.
 */
class ExpireLayawaysJob implements ShouldQueue
{
    use Queueable;
    use RunsForEveryOrganization;

    /** `REQ-INF-003` RB-4. */
    public int $tries = 3;

    public function __construct()
    {
        // `REQ-INF-003` RB-1: la expiración va en `default`.
        $this->onQueue('default');
    }

    /**
     * Dos pasadas a la vez no se pisan (SP-4): un reintento con backoff puede
     * caer encima de la ejecución de la hora siguiente. La idempotencia ya
     * evitaría el daño; esto evita el trabajo doble y los bloqueos cruzados.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('layaways-expire'))->dontRelease()->expireAfter(1800)];
    }

    public function handle(ExpireLayawaysAction $expire): void
    {
        $now = CarbonImmutable::now();
        $total = new ExpirationOutcome;

        $this->forEachOrganization(function (Organization $organization) use ($expire, $now, &$total): void {
            $outcome = $expire->handle($now);

            if ($outcome->held > 0) {
                // Comprobantes sin revisar con la ventana ya vencida: el
                // negocio está reteniendo apartados que ya deberían haber
                // expirado o activado.
                Log::info('layaways.expiration_held', ['organization_id' => (int) $organization->getKey()] + $outcome->toArray());
            }

            $total = $total->plus($outcome);
        });

        Log::info('layaways.expired', $total->toArray());
    }
}
