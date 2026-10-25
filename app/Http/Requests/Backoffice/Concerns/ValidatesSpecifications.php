<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Concerns;

use App\Actions\Product\SpecificationInput;
use App\Actions\Product\SyncSpecificationsAction;

trait ValidatesSpecifications
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function specificationRules(): array
    {
        return [
            'specifications' => ['sometimes', 'array', 'max:'.SyncSpecificationsAction::MAX_ROWS],

            'specifications.*.group' => ['nullable', 'string', 'max:80'],

            'specifications.*.label' => ['required', 'string', 'max:60'],
            'specifications.*.value' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function specificationAttributes(): array
    {
        return [
            'specifications' => 'ficha técnica',
            'specifications.*.group' => 'sección',
            'specifications.*.label' => 'característica',
            'specifications.*.value' => 'valor',
        ];
    }

    /**
     * @return list<SpecificationInput>|null
     */
    protected function specifications(): ?array
    {
        if (! $this->has('specifications')) {
            return null;
        }

        $rows = $this->input('specifications', []);

        if (! is_array($rows)) {
            return [];
        }

        $inputs = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $inputs[] = SpecificationInput::of(
                group: isset($row['group']) && is_scalar($row['group']) ? (string) $row['group'] : null,
                label: isset($row['label']) && is_scalar($row['label']) ? (string) $row['label'] : '',
                value: isset($row['value']) && is_scalar($row['value']) ? (string) $row['value'] : '',
            );
        }

        return $inputs;
    }
}
