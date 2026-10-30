<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Domain\Dashboard\DashboardLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class UpdateDashboardLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(DashboardLayout::MODES)],
            'sections' => ['required', 'array', 'size:'.count(DashboardLayout::SECTIONS)],
            'sections.*' => ['required', 'string', 'distinct', Rule::in(DashboardLayout::SECTIONS)],
            'cards' => ['required', 'array', 'size:'.count(DashboardLayout::CARDS)],
            'cards.*' => ['required', 'string', 'distinct', Rule::in(DashboardLayout::CARDS)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $this->toLayout();
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('cards', $exception->getMessage());
                }
            },
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function toLayout(): DashboardLayout
    {
        /** @var list<string> $sections */
        $sections = array_values((array) $this->input('sections'));
        /** @var list<string> $cards */
        $cards = array_values((array) $this->input('cards'));

        return new DashboardLayout((string) $this->input('mode'), $sections, $cards);
    }
}
