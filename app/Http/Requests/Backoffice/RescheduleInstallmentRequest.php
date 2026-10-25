<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Layaway\StaffReason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class RescheduleInstallmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'due_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:'.StaffReason::MIN, 'max:'.StaffReason::MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['due_date' => 'nueva fecha', 'reason' => 'motivo'];
    }

    public function dueDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('due_date')->toString())
            ?: throw new \LogicException('La regla `date_format` ya validó la fecha.');
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
