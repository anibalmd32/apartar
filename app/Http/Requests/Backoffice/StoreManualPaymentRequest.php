<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Payment\ManualPaymentData;
use App\Domain\Layaway\StaffReason;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\TenantContext;
use App\Http\Requests\Portal\StoreReceiptRequest;
use App\Models\Layaway;
use App\Models\PaymentAccount;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class StoreManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $layaway = $this->route('layaway');

        return $layaway instanceof Layaway && $this->user()?->can('manage', $layaway) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        [$min, $max] = $this->paidAtBounds();

        return [
            'method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'payment_account' => ['nullable', 'integer'],
            'amount' => ['required', 'string', 'regex:'.ApproveReceiptRequest::AMOUNT_PATTERN],
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'rate' => ['nullable', 'string', 'regex:'.ApproveReceiptRequest::RATE_PATTERN],
            'paid_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
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
    public function attributes(): array
    {
        return [
            'method' => 'método',
            'payment_account' => 'cuenta de cobro',
            'amount' => 'importe',
            'currency' => 'moneda',
            'rate' => 'tasa',
            'paid_at' => 'fecha del pago',
            'note' => 'nota',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_at.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'paid_at.after_or_equal' => 'La fecha del pago no puede ser anterior a la creación del apartado.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function toData(): ManualPaymentData
    {
        $rate = $this->string('rate')->trim()->toString();

        try {
            return new ManualPaymentData(
                method: PaymentMethod::from($this->string('method')->toString()),
                paymentAccount: $this->account(),
                amount: $this->string('amount')->toString(),
                currency: Currency::from($this->string('currency')->toString()),
                rateOverride: $rate === '' ? null : $rate,
                paidAt: CarbonImmutable::createFromFormat('!Y-m-d', $this->string('paid_at')->toString(), $this->timezone()) ?: CarbonImmutable::now($this->timezone()),
                note: $this->string('note')->toString(),
                idempotencyKey: $this->string('idempotency_key')->toString(),
                confirmedExcessCents: $this->filled('confirm_excess') ? $this->integer('confirm_excess') : null,
                confirmedStaleRate: $this->filled('confirm_stale_rate') ? $this->string('confirm_stale_rate')->toString() : null,
                confirmedRateDeviation: $this->filled('confirm_rate_deviation') ? $this->string('confirm_rate_deviation')->toString() : null,
            );
        } catch (InvalidRateException $exception) {
            throw ValidationException::withMessages(['rate' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function account(): ?PaymentAccount
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

    /**
     * @return array{0: string, 1: string}
     */
    private function paidAtBounds(): array
    {
        $layaway = $this->route('layaway');

        return $layaway instanceof Layaway
            ? StoreReceiptRequest::boundsFor($layaway, $this->timezone())
            : [CarbonImmutable::now()->toDateString(), CarbonImmutable::now()->toDateString()];
    }

    private function timezone(): DateTimeZone
    {
        return new DateTimeZone(app(TenantContext::class)->organization()->timezone);
    }
}
