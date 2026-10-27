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

final class ApproveReceiptRequest extends FormRequest
{
    public const AMOUNT_PATTERN = '/^\d{1,14}(\.\d{1,2})?$/';

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
            'amount' => 'importe recibido',
            'currency' => 'moneda',
            'rate' => 'tasa',
            'note' => 'nota',
        ];
    }

    /**
     * @throws ValidationException
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
                confirmedRateDeviation: $this->filled('confirm_rate_deviation') ? $this->string('confirm_rate_deviation')->toString() : null,
            );
        } catch (InvalidRateException $exception) {
            throw ValidationException::withMessages(['rate' => $exception->getMessage()]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }
    }
}
