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

/**
 * Lo que manda el formulario del pago manual (`REQ-PAY-007`).
 *
 * Las reglas de forma van aquí, con los bordes que dependen del reloj —la
 * fecha del pago, ni futura (SP-6) ni anterior al apartado, en el calendario
 * del negocio, los mismos que el formulario del comprobante
 * (`StoreReceiptRequest::boundsFor()`)—. Las de fondo —importe, tasa, nota de
 * 10 a 500 (RB-1, SP-1), la clave de idempotencia— las defiende
 * `ManualPaymentData`, y lo que lanza vuelve como `422` en su campo.
 *
 * La cuenta de cobro se busca **por el global scope**: la de otro negocio no
 * existe aquí y vuelve como `422` en su campo (`REQ-ORG-002` RB-7), sin
 * decir que existe. Autoriza la policy del apartado antes de validar; el
 * apartado de otro negocio ni llega (`404` por el binding, SP-8).
 */
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
     * Los datos del pago, ya construidos.
     *
     * @throws ValidationException si la cuenta no es del negocio, o el importe, la tasa o la nota no se pueden usar
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
            );
        } catch (InvalidRateException $exception) {
            throw ValidationException::withMessages(['rate' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            // La nota, la clave y la confirmación ya las validaron las reglas:
            // lo que queda es el importe (positivo, dos decimales, acotado).
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
    }

    /**
     * La cuenta de cobro elegida, **de este negocio**, o ninguna.
     *
     * @throws ValidationException si se eligió una que no es del negocio
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
