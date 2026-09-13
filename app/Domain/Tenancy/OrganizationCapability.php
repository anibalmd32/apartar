<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Lo que se puede hacer dentro de una organización (`REQ-ORG-003` RB-1).
 *
 * RB-1 reparte el backoffice en dos mitades y las enumera con nombre propio:
 * el `owner` gobierna **suscripción, facturación, equipo, ajustes, cuentas de
 * cobro y branding**; el `member` opera **productos, catálogos, clientes,
 * cobranza, comprobantes y chat**. Este enum es ese reparto en forma
 * ejecutable, y existe para que la frontera se declare **una vez**: con el
 * reparto repartido entre policies, cada nueva pantalla volvería a decidirlo
 * por su cuenta y la mitad del `member` se iría encogiendo sin que nadie lo
 * decidiera.
 *
 * Y esa mitad es la que hace útil el rol: un empleado tiene que poder cobrar y
 * conciliar comprobantes —el trabajo diario— sin tocar la facturación del
 * negocio ni dar de alta usuarios. Si se encoge, el `owner` acaba haciéndolo
 * todo y `member` deja de servir para delegar.
 *
 * No es RBAC: no hay capacidades por recurso, ni roles personalizados, ni
 * permisos temporales (`D-4`). Son doce áreas fijas y dos roles.
 */
enum OrganizationCapability: string
{
    // Gobierno del negocio: solo el `owner`.

    /** Plan contratado, cambios de plan y consumo (`REQ-SUB-001`). */
    case Subscription = 'subscription';

    /** Facturas de la suscripción y sus comprobantes de pago (`REQ-SUB-005`). */
    case Billing = 'billing';

    /** Invitar, cambiar de rol, desactivar y reactivar (`REQ-ORG-004`, `REQ-ORG-005`). */
    case Team = 'team';

    /** Ajustes de la organización: tasa, plazos, frecuencia de cuotas (`REQ-ORG-006`). */
    case Settings = 'settings';

    /** Cuentas donde el negocio recibe el dinero (`REQ-ORG-007`). */
    case PaymentAccounts = 'payment_accounts';

    /** Identidad de la vitrina: nombre, slug, logo, color (`REQ-ORG-006`). */
    case Branding = 'branding';

    // Operación diaria: los dos roles.

    /** Alta, edición y archivado de productos (`REQ-CAT-001`). */
    case Products = 'products';

    /** Catálogos y su publicación (`REQ-CAT-010`). */
    case Catalogs = 'catalogs';

    /** Fichas de cliente y su fusión (`REQ-ACC-007`). */
    case Customers = 'customers';

    /** Cobranza: apartados, cuotas y reprogramaciones (`REQ-LAY-001`). */
    case Collections = 'collections';

    /** Comprobantes de pago de los clientes: aprobar y rechazar (`REQ-PAY-003`). */
    case Receipts = 'receipts';

    /** Conversaciones con los clientes (`REQ-CHT-001`). */
    case Chat = 'chat';

    /**
     * Indica si la capacidad es de gobierno y solo la ejerce el `owner`.
     *
     * El `match` es exhaustivo y sin `default` a propósito: añadir un área
     * nueva sin decidir de qué lado de la frontera cae no compila —lanza
     * `UnhandledMatchError` en el primer test que la toque— en lugar de caer
     * por descuido en el lado permisivo, que es como se erosionan estos
     * repartos.
     */
    public function requiresOwner(): bool
    {
        return match ($this) {
            self::Subscription, self::Billing, self::Team,
            self::Settings, self::PaymentAccounts, self::Branding => true,

            self::Products, self::Catalogs, self::Customers,
            self::Collections, self::Receipts, self::Chat => false,
        };
    }

    /**
     * Las seis áreas de gobierno, las que un `member` nunca ejerce.
     *
     * @return list<self>
     */
    public static function ownerOnly(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $capability): bool => $capability->requiresOwner(),
        ));
    }

    /**
     * Las seis áreas de operación diaria, compartidas por los dos roles.
     *
     * @return list<self>
     */
    public static function shared(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $capability): bool => ! $capability->requiresOwner(),
        ));
    }
}
