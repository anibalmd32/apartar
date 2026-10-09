<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\InstallmentStatus;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\OverdueSummary;
use App\Domain\Tenancy\TenantContext;
use App\Models\Installment;
use App\Models\Layaway;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula la mora de los apartados de la organización fijada
 * (`REQ-LAY-007`).
 *
 * La llama `DetectOverdueLayawaysJob` una vez al día por organización, a las
 * 06:00 de **su** zona, con el tenant fijado (RB-5, SP-6). Aquí no se
 * enumeran negocios ni se sale del tenant.
 *
 * ## Qué es «vencida»
 *
 * Una cuota está en mora cuando `due_date + grace_period_days` es **anterior
 * a hoy** y no está cubierta (RB-1, RB-4). Las dos cosas se miden en la
 * organización: «hoy» es la fecha de su zona horaria —calculada aquí, en PHP,
 * nunca con `CURDATE()`— y la gracia es su ajuste. Se compara en días de
 * calendario, porque `due_date` es un `DATE` (`T-039`). Con gracia 0, la
 * cuota está en mora el día siguiente a su vencimiento (SP-7).
 *
 * ## Recalcula, no acumula
 *
 * El estado de cada cuota se **deriva** cada vez de su importe, lo pagado y el
 * umbral (`InstallmentStatus::resolve()`), y solo se escribe si cambia. Eso
 * hace la pasada idempotente (RB-5), recupera sola un día sin job (SP-5) y
 * permite que una gracia ampliada **devuelva** cuotas de `overdue` a
 * `pending` (SP-3). Y el apartado sigue a sus cuotas (RB-2): con al menos una
 * en mora pasa a `overdue`; sin ninguna, vuelve a `active`. La vuelta por
 * pago es de `PaymentApplier` en la transacción del pago (RB-6, `T-058`);
 * esta es la que corresponde a un cambio de gracia.
 *
 * Los terminales no se tocan (SP-4): ni sus cuotas ni su estado.
 *
 * ## Un apartado, una transacción, con el apartado bloqueado
 *
 * Se bloquean el apartado y sus cuotas antes de recalcular, igual que hará el
 * pago: un pago que llegue a la vez espera, y esta pasada ve lo que dejó. No
 * hay stock de por medio —entrar o salir de mora no libera nada—, así que el
 * orden canónico de `REQ-LAY-003` RB-8 no aplica.
 *
 * No pregunta por la suspensión (RB-8) ni cobra nada (RB-3): la mora es un
 * estado y un disparador de avisos.
 */
final class DetectOverdueAction
{
    /** Apartados por consulta al recorrer los candidatos. */
    private const CHUNK = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly LayawayStateMachine $machine,
    ) {}

    /**
     * Una pasada sobre los apartados vivos de la organización fijada.
     *
     * `$now` llega de fuera para que todas las organizaciones de una ejecución
     * se evalúen contra el mismo instante; cada una lo traduce a su «hoy».
     */
    public function handle(CarbonImmutable $now): OverdueSummary
    {
        $threshold = $this->threshold($now);
        $summary = new OverdueSummary;

        Layaway::query()
            ->whereIn('status', [LayawayStatus::Active, LayawayStatus::Overdue])
            ->where(function (Builder $query) use ($threshold): void {
                // Solo los que pueden cambiar: los que ya están en mora (la
                // gracia pudo ampliarse) y los que tienen algo que entra o
                // sale de ella.
                $query->where('status', LayawayStatus::Overdue)
                    ->orWhereHas('installments', fn (Builder $installments) => $installments->unpaidDueBefore($threshold))
                    ->orWhereHas('installments', fn (Builder $installments) => $installments->overdue());
            })
            ->chunkById(self::CHUNK, function (Collection $layaways) use ($threshold, &$summary): void {
                foreach ($layaways as $layaway) {
                    $summary = $this->recalculate($layaway, $threshold, $summary);
                }
            });

        return $summary;
    }

    /**
     * El primer día que **no** está en mora: una cuota cuyo vencimiento es
     * anterior a este está pasada de la gracia.
     *
     * `due_date + gracia < hoy` ⟺ `due_date < hoy − gracia`.
     */
    private function threshold(CarbonImmutable $now): string
    {
        $today = $now->setTimezone(new DateTimeZone($this->context->organization()->timezone))->startOfDay();

        return $today->subDays($this->graceDays())->toDateString();
    }

    /**
     * Si esa cuota ya pasó su gracia en ese instante, medido en el «hoy» y con
     * la gracia de la organización fijada: el `$pastGrace` de
     * `InstallmentStatus::resolve()`.
     *
     * Lo usa la aplicación de pagos (`PaymentApplier`, `T-058`) para el estado
     * de cada cuota que cubre, y es esta regla y no una copia, para que el
     * pago y la pasada diaria no puedan discrepar sobre la misma cuota.
     */
    public function isPastGrace(Installment $installment, CarbonImmutable $now): bool
    {
        return $installment->due_date->toDateString() < $this->threshold($now);
    }

    /**
     * La misma pasada sobre **un** apartado, ahora: para quien acaba de mover
     * algo de lo que su mora depende y no puede esperar a las 06:00.
     *
     * Lo usa la reprogramación (`REQ-LAY-011` RB-5): una cuota en mora que se
     * mueve al futuro sale de `overdue`, y si no queda ninguna el apartado
     * vuelve a `active`. Es la regla de la pasada diaria y no una copia, para
     * que las dos no puedan discrepar. Se puede llamar dentro de una
     * transacción que ya tenga el apartado bloqueado.
     */
    public function refresh(Layaway $layaway, CarbonImmutable $now): OverdueSummary
    {
        return $this->recalculate($layaway, $this->threshold($now), new OverdueSummary);
    }

    private function recalculate(Layaway $layaway, string $threshold, OverdueSummary $summary): OverdueSummary
    {
        return DB::transaction(function () use ($layaway, $threshold, $summary): OverdueSummary {
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->first();

            // SP-4 · pudo completarse o cancelarse desde la lectura del lote.
            if (! $locked instanceof Layaway || ! in_array($locked->status, [LayawayStatus::Active, LayawayStatus::Overdue], true)) {
                return $summary;
            }

            $hasOverdue = false;

            foreach ($locked->installments()->lockForUpdate()->get() as $installment) {
                $target = InstallmentStatus::resolve(
                    $installment->amount_cents,
                    $installment->paid_cents,
                    pastGrace: $installment->due_date->toDateString() < $threshold,
                );

                $hasOverdue = $hasOverdue || $target === InstallmentStatus::Overdue;

                if ($target !== $installment->status) {
                    $summary = $this->move($installment, $target, $summary);
                }
            }

            // RB-2 · el apartado sigue a sus cuotas.
            if ($hasOverdue && $locked->status === LayawayStatus::Active) {
                $this->machine->transition($locked, LayawayStatus::Overdue);

                return $summary->withLayawayBecameOverdue();
            }

            if (! $hasOverdue && $locked->status === LayawayStatus::Overdue) {
                // SP-3 · la gracia se amplió y ya no queda nada en mora.
                $this->machine->transition($locked, LayawayStatus::Active);

                return $summary->withLayawayCaughtUp();
            }

            return $summary;
        });
    }

    private function move(Installment $installment, InstallmentStatus $target, OverdueSummary $summary): OverdueSummary
    {
        $wasOverdue = $installment->status === InstallmentStatus::Overdue;

        $installment->forceFill(['status' => $target])->save();

        return match (true) {
            $target === InstallmentStatus::Overdue => $summary->withInstallmentMarkedOverdue(),
            $wasOverdue => $summary->withInstallmentReturned(),
            default => $summary,
        };
    }

    private function graceDays(): int
    {
        return $this->settings->graceDays();
    }
}
