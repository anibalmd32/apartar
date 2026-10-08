<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La prórroga única de la inicial tras un rechazo tardío
 * (`REQ-LAY-006` RB-3, SP-2, SP-3).
 *
 * Un apartado con un comprobante en revisión no expira aunque se le pase la
 * ventana (RB-2). Si el negocio lo **rechaza** con la ventana ya vencida, el
 * cliente se quedaría sin tiempo para subir otro por una demora que no fue
 * suya: se le dan **24 h desde el rechazo**, una sola vez, y queda marcado en
 * `extended_at`. Una segunda no se concede (SP-3), y el siguiente paso del
 * job lo expira.
 *
 * Quien la llama es el rechazo del comprobante (`RejectReceiptAction`,
 * **`T-062`**), dentro de su transacción y con el tenant fijado. Bloquea solo
 * el apartado —no toca el stock—, así que no entra en el orden canónico de
 * `REQ-LAY-003` RB-8.
 *
 * `expires_at` se mueve: es el **plazo** de la inicial, y la prórroga es
 * exactamente un plazo nuevo. El job no necesita saber nada de prórrogas: lee
 * `expires_at` como siempre.
 */
final class ExtendDownPaymentWindowAction
{
    /** RB-3 · veinticuatro horas desde el rechazo. */
    public const EXTENSION_HOURS = 24;

    public function handle(Layaway $layaway, CarbonImmutable $rejectedAt): DownPaymentExtension
    {
        return DB::transaction(function () use ($layaway, $rejectedAt): DownPaymentExtension {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            $outcome = $this->decide($locked, $rejectedAt);

            if ($outcome->wasGranted()) {
                $locked->forceFill([
                    'expires_at' => $rejectedAt->addHours(self::EXTENSION_HOURS),
                    'extended_at' => $rejectedAt,
                ])->save();
            }

            $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su prórroga.');
            $layaway->setRawAttributes($fresh->getAttributes(), sync: true);

            return $outcome;
        });
    }

    private function decide(Layaway $layaway, CarbonImmutable $rejectedAt): DownPaymentExtension
    {
        if ($layaway->status !== LayawayStatus::PendingDownPayment) {
            return DownPaymentExtension::NotAwaitingDownPayment;
        }

        // SP-3 · una sola vez.
        if ($layaway->extended_at !== null) {
            return DownPaymentExtension::AlreadyExtended;
        }

        // RB-3 · solo si la ventana ya había vencido al rechazar.
        // Abierta **también en el instante exacto** en que vence: es la misma
        // regla que la expiración (`ExpireLayawaysAction`, `expires_at < now`).
        // Al revés, un rechazo justo en ese segundo daba una prórroga a una
        // ventana que la expiración todavía tenía por abierta (`T-062`).
        if ($layaway->expires_at !== null && $layaway->expires_at->greaterThanOrEqualTo($rejectedAt)) {
            return DownPaymentExtension::WindowStillOpen;
        }

        return DownPaymentExtension::Granted;
    }
}
