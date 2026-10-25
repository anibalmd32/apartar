<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

enum AuditArea: string
{
    case Collections = 'collections';

    case Catalog = 'catalog';

    case Customers = 'customers';

    case Organization = 'organization';

    case Subscription = 'subscription';

    case Platform = 'platform';

    public function label(): string
    {
        return match ($this) {
            self::Collections => 'Cobranza',
            self::Catalog => 'Catálogo',
            self::Customers => 'Clientes',
            self::Organization => 'Organización',
            self::Subscription => 'Suscripción',
            self::Platform => 'Plataforma',
        };
    }

    /**
     * @return list<AuditEvent>
     */
    public function events(): array
    {
        return array_values(array_filter(
            AuditEvent::cases(),
            fn (AuditEvent $event): bool => $event->area() === $this,
        ));
    }
}
