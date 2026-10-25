<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum UsageMetric: string
{
    case Users = 'users';

    case Catalogs = 'catalogs';

    case Products = 'products';

    case ActiveLayaways = 'active_layaways';

    case StorageMb = 'storage_mb';

    case PaymentAccounts = 'payment_accounts';

    public function planColumn(): string
    {
        return match ($this) {
            self::Users => 'max_users',
            self::Catalogs => 'max_catalogs',
            self::Products => 'max_products',
            self::ActiveLayaways => 'max_active_layaways',
            self::StorageMb => 'max_storage_mb',
            self::PaymentAccounts => 'max_payment_accounts',
        };
    }

    public function isDerived(): bool
    {
        return match ($this) {
            self::StorageMb => true,

            self::Users, self::Catalogs, self::Products,
            self::ActiveLayaways, self::PaymentAccounts => false,
        };
    }

    public function shrinksWithTime(): bool
    {
        return match ($this) {
            self::Catalogs => true,

            self::Users, self::Products, self::ActiveLayaways,
            self::StorageMb, self::PaymentAccounts => false,
        };
    }

    public function freeUpHint(): string
    {
        return match ($this) {
            self::Users => 'También puedes desactivar a alguien del equipo o revocar una invitación pendiente.',
            self::Catalogs => 'También puedes despublicar otro catálogo para liberar su sitio.',
            self::Products => 'También puedes archivar un producto que ya no vendas para liberar su sitio.',
            self::ActiveLayaways => 'El cupo se libera solo cuando un apartado se completa, se cancela o vence.',
            self::StorageMb => 'También puedes borrar imágenes que ya no uses.',
            self::PaymentAccounts => 'También puedes desactivar otra cuenta de cobro.',
        };
    }

    public function unit(): string
    {
        return $this === self::StorageMb ? 'MB' : 'unidades';
    }

    public function label(int $count = 2): string
    {
        return match ($this) {
            self::Users => $count === 1 ? 'usuario' : 'usuarios',
            self::Catalogs => $count === 1 ? 'catálogo publicado' : 'catálogos publicados',
            self::Products => $count === 1 ? 'producto' : 'productos',
            self::ActiveLayaways => $count === 1 ? 'apartado activo' : 'apartados activos',
            self::StorageMb => 'MB de almacenamiento',
            self::PaymentAccounts => $count === 1 ? 'cuenta de cobro' : 'cuentas de cobro',
        };
    }
}
