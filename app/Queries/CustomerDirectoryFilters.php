<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\CarbonImmutable;

/**
 * Los filtros del directorio de clientes (`REQ-BOF-040` RB-4): la caja de
 * búsqueda, con mora, con vencimiento próximo, sin apartados activos, el
 * rango del próximo vencimiento y el catálogo de origen.
 */
final readonly class CustomerDirectoryFilters
{
    public function __construct(
        public ?string $search = null,
        public bool $overdue = false,
        public bool $dueSoon = false,
        public bool $withoutActive = false,
        public ?CarbonImmutable $nextDueFrom = null,
        public ?CarbonImmutable $nextDueTo = null,
        public ?int $catalogId = null,
    ) {}
}
