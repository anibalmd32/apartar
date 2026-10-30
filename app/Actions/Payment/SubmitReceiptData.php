<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\Layaway;
use App\Models\PaymentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final readonly class SubmitReceiptData
{
    public const NOTE_MAX = 500;

    public const TEXT_MAX = 100;

    /**
     * @throws InvalidArgumentException
     * @throws CrossTenantRelationException
     */
    public function __construct(
        public Layaway $layaway,
        public ?PaymentAccount $paymentAccount,
        public PaymentMethod $method,
        public ?string $bank,
        public ?string $reference,
        public CarbonImmutable $paidAt,
        public string $declaredAmount,
        public Currency $declaredCurrency,
        public ?string $note,
        public UploadedFile $file,
    ) {
        PaymentAmounts::assertPositive($declaredAmount, $declaredCurrency, 'declarado');

        self::assertReferenceFits($method, $bank, $reference);

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidArgumentException(sprintf('La nota tiene como mucho %d caracteres.', self::NOTE_MAX));
        }

        if ($paymentAccount !== null && (int) $paymentAccount->organization_id !== (int) $layaway->organization_id) {
            throw CrossTenantRelationException::between(PaymentAccount::class, Layaway::class);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertReferenceFits(PaymentMethod $method, ?string $bank, ?string $reference): void
    {
        if ($method->requiresReference()) {
            foreach (['banco' => $bank, 'referencia' => $reference] as $field => $value) {
                if ($value === null || trim($value) === '' || mb_strlen($value) > self::TEXT_MAX) {
                    throw new InvalidArgumentException(sprintf(
                        'Un pago por %s lleva %s, de hasta %d caracteres.',
                        $method->label(),
                        $field,
                        self::TEXT_MAX,
                    ));
                }
            }
        } elseif ($bank !== null || $reference !== null) {
            throw new InvalidArgumentException(sprintf(
                'Un pago en %s no lleva banco ni referencia: no deja nada que buscar.',
                mb_strtolower($method->label()),
            ));
        }
    }

    public function declared(): Money
    {
        return Money::fromDecimalString($this->declaredAmount, $this->declaredCurrency);
    }
}
