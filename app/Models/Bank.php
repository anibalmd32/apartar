<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $logo_path
 * @property int $position
 */
class Bank extends Model
{
    /**
     * @var array<string, string>
     */
    public const ALIASES = [
        'venezuela' => '0102',
        'bdv' => '0102',
        'banesco banco universal' => '0134',
        'banco mercantil' => '0105',
        'mercantil banco' => '0105',
        'banco provincial' => '0108',
        'bbva provincial' => '0108',
        'provincial bbva' => '0108',
        'banco provincial bbva' => '0108',
        'bnc' => '0191',
        'banco del caribe' => '0114',
        'banco occidental de descuento' => '0116',
        'exterior' => '0115',
        'tesoro' => '0163',
        'bicentenario' => '0175',
        'plaza' => '0138',
        'activo' => '0171',
        'caroni' => '0128',
        'bfc' => '0151',
        'fondo comun' => '0151',
        'banco venezolano de credito' => '0104',
        'delsur banco universal' => '0157',
        '100%banco' => '0156',
        'banplus banco universal' => '0174',
        'bancamiga banco universal' => '0172',
        'banco sofitasa' => '0137',
    ];

    public static function matching(?string $nameOrCode): ?self
    {
        $typed = trim((string) $nameOrCode);

        if ($typed === '') {
            return null;
        }

        $key = self::key($typed);
        $code = self::ALIASES[$key] ?? (preg_match('/^\d{4}$/', $typed) === 1 ? $typed : null);

        if ($code !== null) {
            return self::query()->where('code', $code)->first();
        }

        return self::query()->orderBy('position')->get()->first(fn (self $bank): bool => self::key($bank->name) === $key);
    }

    public static function canonicalName(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        return $typed === '' ? null : (self::matching($typed)->name ?? $typed);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : asset($this->logo_path);
    }

    /**
     * @return array{id: int, code: string, name: string, logoUrl: string|null}
     */
    public function toCatalogEntry(): array
    {
        return ['id' => (int) $this->getKey(), 'code' => $this->code, 'name' => $this->name, 'logoUrl' => $this->logoUrl()];
    }

    private static function key(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
