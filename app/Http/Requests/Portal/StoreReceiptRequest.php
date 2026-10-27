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
use App\Models\Bank;
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

final class StoreReceiptRequest extends FormRequest
{
    private ?Layaway $layaway = null;

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
                new UniqueReceiptFingerprint($requiresReference ? Bank::canonicalName($this->nullableString('bank')) : null, null),
            ],
            'paidAt' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'amount' => ['required', 'string', $this->positiveAmount()],
            'currency' => ['required', Rule::enum(Currency::class)],
            'note' => ['nullable', 'string', 'max:'.SubmitReceiptData::NOTE_MAX],
            'file' => [
                'required',
                'file',
                'max:'.(ReceiptFile::maxSizeMb() * 1024),
                RealMimeType::receipts(),
                $this->withinPixelLimit(),
                new UniqueReceiptFingerprint(null, null),
            ],
        ];
    }

    /**
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
            'file.max' => sprintf('El comprobante pesa más de %d MB. Si es un PDF, sube mejor una captura.', ReceiptFile::maxSizeMb()),
        ];
    }

    /**
     * @throws InvalidArgumentException
     * @throws CrossTenantRelationException
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
            bank: $method->requiresReference() ? Bank::canonicalName($this->nullableString('bank')) : null,
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
     * @return array{string, string}
     */
    public function paidAtBounds(): array
    {
        return self::boundsFor($this->layaway(), $this->timezone());
    }

    /**
     * @return array{string, string}
     */
    public static function boundsFor(Layaway $layaway, DateTimeZone $timezone): array
    {
        $created = CarbonImmutable::instance($layaway->created_at ?? CarbonImmutable::now())->setTimezone($timezone);

        return [$created->toDateString(), CarbonImmutable::now($timezone)->toDateString()];
    }

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
