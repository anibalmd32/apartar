<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Layaway\ReconcileLayawaysAction;
use App\Domain\Layaway\ReconciliationReport;
use App\Jobs\Concerns\RunsForEveryOrganization;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * La reconciliación de saldos de la plataforma (`REQ-PAY-008` RB-4, RB-6,
 * `T-064`): a diario a las 05:30 de la plataforma, en modo informe.
 *
 * Recorre **todas** las organizaciones —también las suspendidas: es
 * integridad de datos, no una operación de negocio (SP-7)— fijando el tenant
 * de cada una y soltándolo al pasar a la siguiente (`RunsForEveryOrganization`,
 * [90] RB-1b), y deja el resumen de la pasada completa para la pantalla de
 * Salud (`ReconciliationReport::last()`, `REQ-PLT-006` RB-2). Solo una pasada
 * de informe sobre toda la plataforma lo escribe: una con `--fix` contaría lo
 * que acaba de corregir, y una acotada a una organización —el soporte, RB-5—
 * no es «la última de la plataforma».
 *
 * **Una pasada a la vez**, también entre el job y el comando: el comando la
 * ejecuta en el momento, sin pasar por la cola, así que el candado es de
 * `handle()` y no un middleware del job. Si hay otra en curso, esta no
 * corre y devuelve `null`.
 *
 * El comando `layaways:reconcile` la ejecuta en el momento, con `--fix` y
 * `--organization`.
 */
class ReconcileLayaways implements ShouldQueue
{
    use Queueable;
    use RunsForEveryOrganization;

    public int $tries = 3;

    public function __construct(
        public readonly bool $fix = false,
        public readonly ?int $organizationId = null,
    ) {
        // No figura en la tabla de colas de [90] RB-1: va en `default`, con
        // la expiración y la mora, que también son integridad de apartados.
        $this->onQueue('default');
    }

    /** El candado que comparten el job y el comando. */
    public const LOCK = 'layaways:reconcile:running';

    /** Una pasada completa cabe de sobra; si el proceso muere, el candado caduca. */
    private const LOCK_SECONDS = 3600;

    public function handle(ReconcileLayawaysAction $reconcile): ?ReconciliationReport
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('layaways.reconciliation_skipped', ['reason' => 'otra pasada en curso']);

            return null;
        }

        try {
            return $this->run($reconcile);
        } finally {
            $lock->release();
        }
    }

    private function run(ReconcileLayawaysAction $reconcile): ReconciliationReport
    {
        $now = CarbonImmutable::now();
        $total = new ReconciliationReport;

        $this->forEachOrganization(function () use ($reconcile, $now, $total): void {
            $total->merge($reconcile->handle($this->fix, $now));
        });

        if (! $this->fix && $this->organizationId === null) {
            $total->remember($now);
        }

        $total->discrepancies() > 0
            ? Log::warning('layaways.reconciliation', $total->toArray())
            : Log::info('layaways.reconciliation', $total->toArray());

        return $total;
    }

    /**
     * @return iterable<Organization>
     */
    protected function organizationsToVisit(): iterable
    {
        return Organization::query()
            ->when($this->organizationId !== null, fn ($query) => $query->whereKey($this->organizationId))
            ->orderBy('id')
            ->cursor();
    }
}
