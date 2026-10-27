<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $prefix
 * @property string $carrier
 * @property string|null $logo_path
 * @property int $position
 */
class MobilePrefix extends Model
{
    public static function ofPhone(?string $phone): ?self
    {
        $parsed = PhoneNumber::tryFromInput($phone);

        return $parsed === null ? null : self::query()->where('prefix', '0'.$parsed->areaCode())->first();
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : asset($this->logo_path);
    }

    /**
     * @return array{id: int, prefix: string, carrier: string, logoUrl: string|null}
     */
    public function toCatalogEntry(): array
    {
        return ['id' => (int) $this->getKey(), 'prefix' => $this->prefix, 'carrier' => $this->carrier, 'logoUrl' => $this->logoUrl()];
    }
}
