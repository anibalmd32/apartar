<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Domain\Export\ExportType;
use App\Queries\CollectionFilterInput;
use App\Queries\CustomerDirectoryFilterInput;
use App\Queries\InventoryFilters;

/**
 * Qué exportar y con qué filtros (`REQ-BOF-043` RB-3, `T-070`).
 *
 * Los filtros son **los que la pantalla aplicó** (`meta.filters`), no los de
 * la URL: lo que la tabla ignoró tampoco entra en el archivo. Se guardan solo
 * las claves que ese tipo sabe leer y solo como texto —son valores de query
 * string—, de modo que en `export_jobs.filters` no acaba nada que el job no
 * vaya a interpretar con la misma regla que la pantalla.
 */
final readonly class ExportRequestData
{
    /** Longitud máxima de un valor de filtro guardado. */
    public const VALUE_MAX = 100;

    /**
     * @param  array<string, string>  $filters
     */
    private function __construct(
        public ExportType $type,
        public array $filters,
    ) {}

    /**
     * @param  array<mixed>  $filters  lo que mandó la pantalla
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
     * Las claves que lee la pantalla de cada tipo.
     *
     * @return list<string>
     */
    public static function keysOf(ExportType $type): array
    {
        return match ($type) {
            ExportType::Collections => CollectionFilterInput::KEYS,
            ExportType::Customers => CustomerDirectoryFilterInput::KEYS,
            ExportType::Inventory => InventoryFilters::KEYS,
        };
    }
}
