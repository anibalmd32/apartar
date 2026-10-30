<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Concerns;

use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\SubmitReceiptData;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\Backoffice\ApproveReceiptRequest;
use App\Http\Requests\Portal\StoreReceiptRequest;
use App\Models\Bank;
use App\Models\PaymentAccount;
use App\Rules\RealMimeType;
use App\Rules\UniqueReceiptFingerprint;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait ValidatesManualPayment
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function paymentRules(string $earliest, string $latest): array
    {
        $method = PaymentMethod::tryFrom($this->string('method')->toString());
        $requiresReference = $method?->requiresReference() ?? false;
        $withoutReference = $method !== null && ! $requiresReference;

        return [
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'payment_account' => ['nullable', 'integer'],
            'amount' => ['required', 'string', 'regex:'.ApproveReceiptRequest::AMOUNT_PATTERN],
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'rate' => ['nullable', 'string', 'regex:'.ApproveReceiptRequest::RATE_PATTERN],
            'bank' => [Rule::requiredIf($requiresReference), Rule::prohibitedIf($withoutReference), 'nullable', 'string', 'max:'.SubmitReceiptData::TEXT_MAX],
            'reference' => [
                Rule::requiredIf($requiresReference),
                Rule::prohibitedIf($withoutReference),
                'nullable',
                'string',
                'max:'.SubmitReceiptData::TEXT_MAX,
                new UniqueReceiptFingerprint($requiresReference ? Bank::canonicalName($this->nullableString('bank')) : null, null, recordedByBusiness: true, exceptIdempotencyKey: $this->paymentIdempotencyKey()),
            ],
            'file' => [
                'nullable',
                'file',
                'max:'.(ReceiptFile::maxSizeMb() * 1024),
                RealMimeType::receipts(),
                StoreReceiptRequest::withinPixelLimit(),
                new UniqueReceiptFingerprint(null, null, recordedByBusiness: true, exceptIdempotencyKey: $this->paymentIdempotencyKey()),
            ],
            'paid_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$earliest, 'before_or_equal:'.$latest],
            'note' => ['required', 'string', 'min:'.StaffReason::MIN, 'max:'.StaffReason::MAX],
            'idempotency_key' => ['required', 'string', 'uuid'],
            'confirm_excess' => ['nullable', 'integer', 'min:1'],
            'confirm_stale_rate' => ['nullable', 'string', 'max:100'],
            'confirm_rate_deviation' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paymentAttributes(): array
    {
        return [
            'method' => 'método',
            'payment_account' => 'cuenta de cobro',
            'amount' => 'importe',
            'currency' => 'moneda',
            'rate' => 'tasa',
            'paid_at' => 'fecha del pago',
            'bank' => 'banco',
            'reference' => 'referencia',
            'file' => 'comprobante',
            'note' => 'nota',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function paymentMessages(): array
    {
        return [
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'paid_at.after_or_equal' => 'La fecha del pago no puede ser anterior a la creación del apartado.',
            'bank.prohibited' => 'Un pago en efectivo no lleva banco ni referencia.',
            'reference.prohibited' => 'Un pago en efectivo no lleva banco ni referencia.',
            'file.max' => sprintf('El comprobante pesa más de %d MB. Si es un PDF, sube mejor una captura.', ReceiptFile::maxSizeMb()),
        ];
    }

    /**
     * @throws ValidationException
     */
    protected function paymentData(): ManualPaymentData
    {
        $rate = $this->string('rate')->trim()->toString();
        $method = PaymentMethod::from($this->string('method')->toString());
        $file = $this->file('file');

        try {
            return new ManualPaymentData(
                method: $method,
                paymentAccount: $this->paymentAccount(),
                amount: $this->string('amount')->toString(),
                currency: Currency::from($this->string('currency')->toString()),
                rateOverride: $rate === '' ? null : $rate,
                paidAt: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('paid_at')->toString(), $this->organizationTimezone()) ?: CarbonImmutable::now($this->organizationTimezone()),
                note: $this->string('note')->toString(),
                idempotencyKey: $this->string('idempotency_key')->toString(),
                confirmedExcessCents: $this->filled('confirm_excess') ? $this->integer('confirm_excess') : null,
                confirmedStaleRate: $this->filled('confirm_stale_rate') ? $this->string('confirm_stale_rate')->toString() : null,
                confirmedRateDeviation: $this->filled('confirm_rate_deviation') ? $this->string('confirm_rate_deviation')->toString() : null,
                bank: $method->requiresReference() ? Bank::canonicalName($this->nullableString('bank')) : null,
                reference: $method->requiresReference() ? $this->nullableString('reference') : null,
                file: $file instanceof UploadedFile ? $file : null,
            );
        } catch (InvalidRateException $exception) {
            throw ValidationException::withMessages(['rate' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
    }

    protected function organizationTimezone(): DateTimeZone
    {
        return new DateTimeZone(app(TenantContext::class)->organization()->timezone);
    }

    /**
     * @throws ValidationException
     */
    private function paymentAccount(): ?PaymentAccount
    {
        if (! $this->filled('payment_account')) {
            return null;
        }

        $account = PaymentAccount::query()->find($this->integer('payment_account'));

        if (! $account instanceof PaymentAccount) {
            throw ValidationException::withMessages(['payment_account' => 'Esa cuenta de cobro no es de tu negocio.']);
        }

        return $account;
    }

    private function paymentIdempotencyKey(): ?string
    {
        $key = $this->input('idempotency_key');

        return is_string($key) && Str::isUuid($key) ? $key : null;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
