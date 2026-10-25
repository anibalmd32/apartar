<?php

declare(strict_types=1);

namespace App\Actions\Product;

use InvalidArgumentException;

final readonly class SpecificationInput
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public ?string $group,
        public string $label,
        public string $value,
    ) {
        if ($label === '') {
            throw new InvalidArgumentException(
                'Una fila de ficha técnica necesita su característica. '
                .'ValidatesSpecifications la comprueba antes con el índice de la fila: si esto lanza, las '
                .'filas se construyeron sin pasar por la validación.'
            );
        }
    }

    public static function of(?string $group, string $label, string $value): self
    {
        $group = self::collapse($group ?? '');

        return new self(
            group: $group === '' ? null : $group,
            label: self::collapse($label),
            value: trim($value),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(int $productId, int $position): array
    {
        return [
            'product_id' => $productId,
            'group' => $this->group,
            'label' => $this->label,
            'value' => $this->value,
            'position' => $position,
        ];
    }

    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
