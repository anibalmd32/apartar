<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Account\PortalContext;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentAmounts;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\PaymentAccount;
use App\Rules\RealMimeType;
use App\Rules\UniqueReceiptFingerprint;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * El comprobante que sube el cliente, validado (`REQ-PAY-001`, `REQ-PAY-002`
 * RB-5, `REQ-PAY-003`).
 *
 * Valida lo que se puede decir **antes** de tocar nada, cada cosa en su campo:
 *
 *  - **La fecha de pago, en el calendario del negocio** (RB-4, SP-5): ni
 *    futura ni anterior al día en que se creó el apartado. Los dos bordes son
 *    días de la zona de la organización —un cliente que paga a las 23:00 de
 *    Caracas en un negocio de Tokio ya está en «mañana»—, y son los mismos que
 *    `ReceiptController::create()` le da al formulario (`paidAtBounds()`).
 *  - **La cuenta de cobro, activa y de este negocio** (RB-3, SP-7): se busca
 *    por el global scope, así que la de otro negocio no existe y vuelve como
 *    error de su campo, sin decir que existe en otra parte.
 *  - **El archivo** (`REQ-PAY-002` RB-5): el tamaño, el tipo **real** por
 *    contenido (`RealMimeType::receipts()`, que admite PDF), un tope de píxeles
 *    antes de que nadie lo abra (SP-8), y que no se haya reportado ya en esta
 *    organización (`UniqueReceiptFingerprint`, `REQ-PAY-003`). La acción lo
 *    vuelve a comprobar bajo el bloqueo: aquí es para que el cliente vea el
 *    error junto al campo, allí para que una carrera no lo cuele.
 *
 * El importe, el banco, la referencia y la nota los vuelve a exigir
 * `SubmitReceiptData` al construirse; las reglas dicen lo mismo para que el
 * error llegue con su campo y no como un fallo sin dueño.
 *
 * Los nombres son los de `ReceiptFormValues` (`resources/js/types/receipt.ts`),
 * en *camelCase*. `paymentAccount` y no otra cosa: el frontend no puede
 * nombrar la columna (`AccountBoundaryTest`).
 */
final class StoreReceiptRequest extends FormRequest
{
    private ?Layaway $layaway = null;

    /**
     * SP-4 · un apartado que ya no cobra no recibe comprobantes: `403`, y
     * antes de validar nada, para que un terminal no conteste con los
     * errores de un formulario que no tiene sentido rellenar.
     */
    public function authorize(): bool
    {
        return $this->layaway()->status->acceptsPayments();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        [$min, $max] = $this->paidAtBounds();
        $method = PaymentMethod::tryFrom((string) $this->string('method'));
        $requiresReference = $method?->requiresReference() ?? false;

        return [
            'paymentAccount' => ['nullable', 'integer'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'bank' => [Rule::requiredIf($requiresReference), 'nullable', 'string', 'max:'.SubmitReceiptData::TEXT_MAX],
            'reference' => [
                Rule::requiredIf($requiresReference),
                'nullable',
                'string',
                'max:'.SubmitReceiptData::TEXT_MAX,
                // `REQ-PAY-003` (b), en su campo: la misma pareja ya reportada.
                new UniqueReceiptFingerprint($requiresReference ? $this->nullableString('bank') : null, null),
            ],
            'paidAt' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'amount' => ['required', 'string', $this->positiveAmount()],
            'currency' => ['required', Rule::enum(Currency::class)],
            'note' => ['nullable', 'string', 'max:'.SubmitReceiptData::NOTE_MAX],
            'file' => [
                'required',
                'file',
                'max:'.(ReceiptFile::MAX_SIZE_MB * 1024),
                RealMimeType::receipts(),
                $this->withinPixelLimit(),
                // `REQ-PAY-003` (a): el mismo archivo. La referencia tiene su
                // propia regla, para que su duplicado vuelva en su campo.
                new UniqueReceiptFingerprint(null, null),
            ],
        ];
    }

    /**
     * La cuenta de cobro, después de las reglas: si se mandó, tiene que ser
     * una activa de este negocio (SP-7).
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('paymentAccount') && $this->paymentAccount() === null) {
                    $validator->errors()->add('paymentAccount', 'Esa cuenta de cobro no está disponible. Elige otra de la lista.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'paymentAccount' => 'cuenta de cobro',
            'method' => 'método de pago',
            'bank' => 'banco',
            'reference' => 'referencia',
            'paidAt' => 'fecha de pago',
            'amount' => 'monto',
            'currency' => 'moneda',
            'note' => 'nota',
            'file' => 'comprobante',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paidAt.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'paidAt.after_or_equal' => 'La fecha de pago no puede ser anterior a la creación del apartado.',
            'file.max' => sprintf('El comprobante pesa más de %d MB. Si es un PDF, sube mejor una captura.', ReceiptFile::MAX_SIZE_MB),
        ];
    }

    /**
     * Los datos de la acción. En efectivo, banco y referencia se descartan
     * aunque hayan llegado: el método no deja nada que buscar
     * (`REQ-PAY-003` RB-3), y el DTO los rechazaría.
     *
     * @throws InvalidArgumentException si algo no cumple lo que el DTO exige
     * @throws CrossTenantRelationException si la cuenta de cobro no es del negocio del apartado (SP-7)
     */
    public function toData(Layaway $layaway): SubmitReceiptData
    {
        $method = PaymentMethod::from((string) $this->string('method'));
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new InvalidArgumentException('El comprobante no llegó como archivo.');
        }

        return new SubmitReceiptData(
            layaway: $layaway,
            paymentAccount: $this->paymentAccount(),
            method: $method,
            bank: $method->requiresReference() ? $this->nullableString('bank') : null,
            reference: $method->requiresReference() ? $this->nullableString('reference') : null,
            paidAt: CarbonImmutable::createFromFormat('Y-m-d', (string) $this->string('paidAt'), $this->timezone())?->startOfDay()
                ?? throw new InvalidArgumentException('La fecha de pago no tiene forma de fecha.'),
            declaredAmount: (string) $this->string('amount'),
            declaredCurrency: Currency::from((string) $this->string('currency')),
            note: $this->nullableString('note'),
            file: $file,
        );
    }

    /**
     * El primer y el último día admitidos para la fecha de pago, en la zona
     * del negocio: el de creación del apartado y hoy (RB-4).
     *
     * @return array{string, string}
     */
    public function paidAtBounds(): array
    {
        return self::boundsFor($this->layaway(), $this->timezone());
    }

    /**
     * La misma cuenta, para el formulario: la usan las reglas y
     * `ReceiptController::create()`, y así no pueden discrepar.
     *
     * @return array{string, string}
     */
    public static function boundsFor(Layaway $layaway, DateTimeZone $timezone): array
    {
        $created = CarbonImmutable::instance($layaway->created_at ?? CarbonImmutable::now())->setTimezone($timezone);

        return [$created->toDateString(), CarbonImmutable::now($timezone)->toDateString()];
    }

    /**
     * El apartado de la URL, entre las fichas de la sesión. Uno ajeno es el
     * mismo `404` que en el detalle (`PortalContext::findLayaway()`).
     */
    private function layaway(): Layaway
    {
        return $this->layaway ??= app(PortalContext::class)->findLayaway((string) $this->route('code'));
    }

    private function paymentAccount(): ?PaymentAccount
    {
        if (! $this->filled('paymentAccount')) {
            return null;
        }

        /** @var PaymentAccount|null */
        return PaymentAccount::query()->where('is_active', true)->find($this->integer('paymentAccount'));
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone(app(TenantContext::class)->organization()->timezone);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * RB-5 · positivo y con dos decimales como mucho: la regla de
     * `PaymentAmounts`, la misma que aplicará el DTO.
     */
    private function positiveAmount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $currency = Currency::tryFrom((string) $this->string('currency')) ?? Currency::USD;

            try {
                PaymentAmounts::assertPositive((string) $value, $currency, 'declarado');
            } catch (InvalidArgumentException) {
                $fail('El monto tiene que ser mayor que cero, con dos decimales como mucho (por ejemplo, 850.00).');
            }
        };
    }

    /**
     * `REQ-PAY-002` SP-8 · una imagen de dimensiones absurdas se rechaza
     * leyendo solo su cabecera, antes de que la recompresión la abra.
     */
    private function withinPixelLimit(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $size = @getimagesize($value->getRealPath() ?: '');

            if ($size !== false && $size[0] * $size[1] > ReceiptFile::MAX_PIXELS) {
                $fail('La imagen es demasiado grande en píxeles. Sube una captura de pantalla normal.');
            }
        };
    }
}
