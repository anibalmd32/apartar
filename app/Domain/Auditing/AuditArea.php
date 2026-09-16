<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

/**
 * Los seis bloques en los que `REQ-BOF-005` RB-1 agrupa lo que se audita.
 *
 * No es una taxonomía inventada para la pantalla: es literalmente la estructura
 * de la lista cerrada del requisito. Existe como enum y no como una cadena
 * derivada del prefijo del evento porque el filtro de la vista necesita
 * ofrecerlos —treinta y cinco eventos en un desplegable plano no se leen— y un
 * `explode('.', $event->value)[0]` habría atado la agrupación a cómo se
 * escribieron los valores, que es un detalle que nadie prometió mantener.
 */
enum AuditArea: string
{
    /** Comprobantes, pagos, apartados y entregas (`REQ-PAY-*`, `REQ-LAY-*`). */
    case Collections = 'collections';

    /** Productos y catálogos (`REQ-CAT-*`, `REQ-BOF-020`). */
    case Catalog = 'catalog';

    /** Fichas de cliente y cuentas de consumidor (`REQ-IDN-*`, `REQ-ACC-*`). */
    case Customers = 'customers';

    /** Ajustes, identidad, cuentas de cobro y equipo (`REQ-ORG-*`). */
    case Organization = 'organization';

    /** Plan, facturas, degradación y suspensión (`REQ-SUB-*`). */
    case Subscription = 'subscription';

    /** Lo que Apartar hace sobre el negocio (`REQ-PLT-004`, `REQ-PLT-005`). */
    case Platform = 'platform';

    /**
     * Nombre del bloque tal como lo lee el dueño del negocio.
     *
     * El `match` es exhaustivo y sin `default`: un bloque nuevo tiene que
     * decidir cómo se llama en vez de aparecer en la pantalla con su valor
     * crudo.
     */
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
     * Eventos de este bloque.
     *
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
