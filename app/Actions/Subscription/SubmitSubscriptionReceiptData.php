<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentMethod;
use App\Models\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Lo que el negocio declara al pagar una factura de su suscripción
 * (`REQ-SUB-006` RB-1, RB-2, `T-069`): lo mismo que un cliente declara al
 * pagarle a él (`SubmitReceiptData`), sin apartado ni cuenta de cobro del
 * negocio —se paga a la plataforma—.
 *
 * No se puede construir con un importe que no sea un decimal positivo, ni con
 * una referencia vacía en un método que la exige: esas comprobaciones llegan
 * antes que el archivo al disco.
 */
final readonly class SubmitSubscriptionReceiptData
{
    public const TEXT_MAX = 100;

    /**
     * @throws InvalidArgumentException si el importe, el banco o la referencia no tienen forma válida
     */
    public function __construct(
        public SubscriptionInvoice $invoice,
        public PaymentMethod $method,
        public ?string $bank,
        public ?string $reference,
        public CarbonImmutable $paidAt,
        public string $declaredAmount,
        public Currency $declaredCurrency,
        public UploadedFile $file,
    ) {
        PaymentAmounts::assertPositive($declaredAmount, $declaredCurrency, 'declarado');

        foreach (['banco' => $bank, 'referencia' => $reference] as $what => $text) {
            if ($text !== null && (trim($text) === '' || mb_strlen($text) > self::TEXT_MAX)) {
                throw new InvalidArgumentException(sprintf('El %s tiene entre 1 y %d caracteres.', $what, self::TEXT_MAX));
            }
        }

        if ($method->requiresReference() && $reference === null) {
            throw new InvalidArgumentException(sprintf('Un pago por %s lleva su referencia.', $method->label()));
        }
    }
}
