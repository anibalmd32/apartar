<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Actions\Layaway\ExtendDownPaymentWindowAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\ReceiptAlreadyProcessedException;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * El negocio rechaza un comprobante, diciendo por qué (`REQ-PAY-006`).
 *
 * Es el hermano de `ApproveReceiptAction`, y se comporta igual alrededor: la
 * misma policy, el mismo orden de bloqueo —**apartado y después
 * comprobante**— y la misma comprobación bajo el bloqueo, así que un rechazo
 * y una aprobación simultáneos se serializan y el segundo recibe el nombre de
 * quien lo resolvió (SP-1, SP-5).
 *
 *  1. **El motivo**: un código del catálogo y un texto de 10 a 500 caracteres
 *     (RB-1). El código `layaway_cancelled` no lo elige nadie: es el del
 *     rechazo automático al cancelar (SP-6).
 *  2. **El comprobante, rechazado** con su código, su texto, quién y cuándo.
 *     **Ni el archivo ni la fila se borran** (RB-2): son la evidencia. Y la
 *     referencia sigue ocupada (RB-5): los duplicados miran también los
 *     rechazados.
 *  3. **La prórroga de la inicial** (RB-6): si el apartado espera la inicial y
 *     su ventana ya venció al rechazar, `ExtendDownPaymentWindowAction` da las
 *     24 h una vez. Solo bloquea el apartado, que ya está bloqueado aquí.
 *  4. **La auditoría** con el código, el texto y la prórroga (RB-7).
 *
 * El texto lo verá el cliente **íntegro** (RB-3, `REQ-LAY-022` RB-3). El
 * aviso al cliente es de `T-083`, como el de la aprobación.
 */
final class RejectReceiptAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly ExtendDownPaymentWindowAction $extension) {}

    /**
     * @throws AuthorizationException si quien rechaza no concilia en esa organización
     * @throws InvalidArgumentException si el código es el automático o el texto no tiene entre 10 y 500 caracteres
     * @throws ReceiptAlreadyProcessedException si ya no está en revisión (SP-1, SP-5)
     */
    public function handle(Receipt $receipt, RejectionReasonCode $code, string $reason, User $actor): DownPaymentExtension
    {
        Gate::forUser($actor)->authorize('review', $receipt);

        if ($code === RejectionReasonCode::LayawayCancelled) {
            throw new InvalidArgumentException('Ese motivo es el del rechazo automático al cancelar el apartado; elige otro (REQ-PAY-006 SP-6).');
        }

        $text = new StaffReason($reason);

        return DB::transaction(function () use ($receipt, $code, $text, $actor): DownPaymentExtension {
            /** @var Layaway $layaway */
            $layaway = Layaway::query()->whereKey($receipt->layaway_id)->lockForUpdate()->firstOrFail();

            /** @var Receipt $locked */
            $locked = Receipt::query()->whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->awaitsReview()) {
                throw ReceiptAlreadyProcessedException::of($locked);
            }

            $now = CarbonImmutable::now();

            $locked->forceFill([
                'status' => ReceiptStatus::Rejected,
                'rejection_reason_code' => $code,
                'rejection_reason' => $text->text,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => $now,
            ])->save();

            $extension = $this->extension->handle($layaway, $now);

            $this->audit(
                AuditEvent::ReceiptRejected,
                subject: $locked,
                actor: $actor,
                before: ['status' => ReceiptStatus::PendingReview->value],
                after: [
                    'status' => ReceiptStatus::Rejected->value,
                    'rejection_reason_code' => $code->value,
                    'rejection_reason' => $text->text,
                ] + ($extension->wasGranted() ? ['down_payment_extended_until' => $layaway->expires_at?->toIso8601String()] : []),
            );

            ModelState::adopt($receipt, $locked->getAttributes());

            return $extension;
        });
    }
}
