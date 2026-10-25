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

final readonly class SubmitSubscriptionReceiptData
{
    public const TEXT_MAX = 100;

    /**
     * @throws InvalidArgumentException
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
