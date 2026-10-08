<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Payment\ApproveReceiptData;
use App\Domain\Money\Currency;
use App\Domain\Money\InvalidRateException;
use App\Models\Receipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Lo que manda el formulario de aprobación (`REQ-PAY-005`): el importe
 * realmente recibido y su moneda (RB-3), la tasa si se edita a mano (SP-7),
 * una nota y la confirmación del sobrante (SP-5).
 *
 * Las reglas de forma van aquí; las de fondo —importe positivo de dos
 * decimales y acotado, tasa usable y que quepa en `DECIMAL(18,8)`— las
 * defiende `ApproveReceiptData`, y lo que lanza al construirse vuelve como
 * `422` en su campo en vez de repetirse escrito dos veces.
 *
 * Autoriza la policy: quien no concilia en esta organización recibe `403`
 * antes de validar nada. El de otra organización ni llega: `404` por el
 * binding.
 */
final class ApproveReceiptRequest extends FormRequest
{
    /** El importe: hasta catorce cifras enteras y dos decimales, con punto (`PaymentAmounts`). */
    public const AMOUNT_PATTERN = '/^\d{1,14}(\.\d{1,2})?$/';

    /** La tasa: hasta diez cifras enteras y ocho decimales, lo que cabe en `rate_applied` (`ManualRate`). */
    public const RATE_PATTERN = '/^\d{1,10}(\.\d{1,8})?$/';

    public function authorize(): bool
    {
        $receipt = $this->route('receipt');

        return $receipt instanceof Receipt && $this->user()?->can('review', $receipt) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:'.self::AMOUNT_PATTERN],
            'currency' => ['required', 'string', Rule::enum(Currency::class)],
            'rate' => ['nullable', 'string', 'regex:'.self::RATE_PATTERN],
            'note' => ['nullable', 'string', 'max:'.ApproveReceiptData::NOTE_MAX],
            // Los centavos de sobrante que se vieron y se confirmaron (SP-5).
            'confirm_excess' => ['nullable', 'integer', 'min:1'],
            // La tasa vieja que se confirmó (`REQ-FX-006` SP-5, `StaleRate::token()`).
            'confirm_stale_rate' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => 'importe recibido',
            'currency' => 'moneda',
            'rate' => 'tasa',
            'note' => 'nota',
        ];
    }

    /**
     * Los datos de la aprobación, ya construidos.
     *
     * @throws ValidationException si el importe o la tasa no se pueden usar
     */
    public function toData(): ApproveReceiptData
    {
        $rate = $this->string('rate')->trim()->toString();
        $note = $this->string('note')->trim()->toString();

        try {
            return new ApproveReceiptData(
                settledAmount: $this->string('amount')->toString(),
                settledCurrency: Currency::from($this->string('currency')->toString()),
                rateOverride: $rate === '' ? null : $rate,
                note: $note === '' ? null : $note,
                confirmedExcessCents: $this->filled('confirm_excess') ? $this->integer('confirm_excess') : null,
                confirmedStaleRate: $this->filled('confirm_stale_rate') ? $this->string('confirm_stale_rate')->toString() : null,
            );
        } catch (InvalidRateException $exception) {
            throw ValidationException::withMessages(['rate' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
    }
}
