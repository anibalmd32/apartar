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

/**
 * Lo que el cliente declara al subir un comprobante (`REQ-PAY-001`).
 *
 * Es lo que recibe `SubmitReceiptAction` (`T-059`), y **no se puede construir
 * con lo que la tabla rechazaría o la regla prohíbe**:
 *
 *  - **El importe declarado es positivo y de dos decimales** (RB-5), en su
 *    moneda y sin convertir: la conversión es de la aprobación
 *    (`REQ-FX-006`). Lo decide `PaymentAmounts`, la regla común a los tres
 *    importes de un pago.
 *  - **Banco y referencia, según el método** (RB-2): los dos si el método deja
 *    referencia, ninguno en efectivo. Es la misma pareja que el `CHECK` de
 *    `receipts` (`T-054`), dicha antes de llegar a la base.
 *  - **La cuenta de cobro es del negocio del apartado** (SP-7, `REQ-ORG-002`
 *    RB-7). Una cuenta de otra organización en el payload es un dato
 *    imposible, y aquí se para.
 *
 * Lo que depende del reloj —que `paidAt` no sea futura ni anterior a la
 * creación del apartado (RB-4, SP-5)— lo comprueba el formulario, con el
 * «hoy» de la petición: un constructor no tiene por qué saber qué hora es.
 */
final readonly class SubmitReceiptData
{
    public const NOTE_MAX = 500;

    public const TEXT_MAX = 100;

    /**
     * @throws InvalidArgumentException si el importe, el banco, la referencia o la nota no tienen forma válida
     * @throws CrossTenantRelationException si la cuenta de cobro es de otra organización
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

        if ($method->requiresReference()) {
            foreach (['banco' => $bank, 'referencia' => $reference] as $field => $value) {
                if ($value === null || trim($value) === '' || mb_strlen($value) > self::TEXT_MAX) {
                    throw new InvalidArgumentException(sprintf(
                        'Un pago por %s lleva %s, de hasta %d caracteres (REQ-PAY-001 RB-2).',
                        $method->label(),
                        $field,
                        self::TEXT_MAX,
                    ));
                }
            }
        } elseif ($bank !== null || $reference !== null) {
            throw new InvalidArgumentException(sprintf(
                'Un pago en %s no lleva banco ni referencia: no deja nada que buscar (REQ-PAY-003 RB-3).',
                mb_strtolower($method->label()),
            ));
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX) {
            throw new InvalidArgumentException(sprintf('La nota tiene como mucho %d caracteres.', self::NOTE_MAX));
        }

        if ($paymentAccount !== null && (int) $paymentAccount->organization_id !== (int) $layaway->organization_id) {
            throw CrossTenantRelationException::between(PaymentAccount::class, Layaway::class);
        }
    }

    /**
     * El importe declarado como `Money` de su moneda.
     */
    public function declared(): Money
    {
        return Money::fromDecimalString($this->declaredAmount, $this->declaredCurrency);
    }
}
