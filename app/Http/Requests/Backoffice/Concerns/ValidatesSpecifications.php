<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Concerns;

use App\Actions\Product\SyncSpecificationsAction;
use Illuminate\Validation\Validator;

trait ValidatesSpecifications
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function specificationRules(): array
    {
        return [
            'specifications' => ['sometimes', 'array'],
            'specifications.*' => ['nullable', 'string', 'max:'.SyncSpecificationsAction::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function specificationAttributes(): array
    {
        return [
            'specifications' => 'características',
            'specifications.*' => 'característica',
        ];
    }

    /**
     * @return callable(Validator): void
     */
    protected function specificationChecks(): callable
    {
        return function (Validator $validator): void {
            $rows = $this->input('specifications');

            if (! is_array($rows)) {
                return;
            }

            $count = count(SyncSpecificationsAction::normalized($rows));

            if ($count > SyncSpecificationsAction::MAX_ROWS) {
                $validator->errors()->add('specifications', sprintf(
                    'Un producto admite hasta %d características y escribiste %d. Quita las que sobren.',
                    SyncSpecificationsAction::MAX_ROWS,
                    $count,
                ));
            }

            $seen = [];

            foreach ($rows as $index => $row) {
                $key = mb_strtolower(implode('', SyncSpecificationsAction::normalized([$row])));

                if ($key === '') {
                    continue;
                }

                if (isset($seen[$key])) {
                    $validator->errors()->add(
                        'specifications.'.$index,
                        'Esta característica ya está en la lista. Quítala o escribe otra distinta.',
                    );

                    continue;
                }

                $seen[$key] = true;
            }
        };
    }

    /**
     * @return list<string>|null
     */
    protected function specifications(): ?array
    {
        if (! $this->has('specifications')) {
            return null;
        }

        $rows = $this->input('specifications', []);

        return is_array($rows) ? SyncSpecificationsAction::normalized($rows) : [];
    }
}
