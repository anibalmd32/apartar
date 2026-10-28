<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property int $position
 */
class DocumentPrefix extends Model
{
    /**
     * @return array{id: int, code: string, name: string}
     */
    public function toCatalogEntry(): array
    {
        return ['id' => (int) $this->getKey(), 'code' => $this->code, 'name' => $this->name];
    }
}
