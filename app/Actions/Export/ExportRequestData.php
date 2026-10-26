<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Domain\Export\ExportType;
use App\Queries\CollectionFilterInput;
use App\Queries\CustomerDirectoryFilterInput;
use App\Queries\InventoryFilters;

final readonly class ExportRequestData
{
    public const VALUE_MAX = 100;

    /**
     * @param  array<string, string>  $filters
     */
    private function __construct(
        public ExportType $type,
        public array $filters,
    ) {}

    /**
     * @param  array<mixed>  $filters
     */
    public static function of(ExportType $type, array $filters): self
    {
        $kept = [];

        foreach (self::keysOf($type) as $key) {
            $value = $filters[$key] ?? null;

            if (is_string($value) && $value !== '' && mb_strlen($value) <= self::VALUE_MAX) {
                $kept[$key] = $value;
            }
        }

        return new self($type, $kept);
    }

    /**
     * @return list<string>
     */
    public static function keysOf(ExportType $type): array
    {
        return match ($type) {
            ExportType::Collections => CollectionFilterInput::KEYS,
            ExportType::Customers => CustomerDirectoryFilterInput::KEYS,
            ExportType::Inventory => InventoryFilters::KEYS,
            ExportType::Payments => [],
        };
    }
}
