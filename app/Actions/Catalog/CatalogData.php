<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use Illuminate\Http\UploadedFile;

final readonly class CatalogData
{
    public function __construct(
        public string $name,
        public ?string $slug,
        public ?string $description,
        public ?UploadedFile $cover,
    ) {}
}
