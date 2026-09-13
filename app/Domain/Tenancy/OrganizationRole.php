<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Rol de un usuario dentro de su organización (`REQ-ORG-003` RB-1).
 *
 * Solo dos, y es una decisión cerrada (`D-4`): sin RBAC granular, sin roles
 * personalizados, sin permisos temporales. La complejidad de un sistema de
 * permisos no está en escribirlo, está en que después nadie sabe explicar por
 * qué alguien ve algo; con dos roles y doce áreas, la respuesta cabe en una
 * frase.
 *
 * El rol se lee **de la base de datos en cada petición**, nunca de un claim de
 * la sesión (RB-6). Con el rol cacheado en sesión, degradar a alguien sería
 * una sugerencia hasta que le apeteciera volver a entrar; leyéndolo de la
 * tabla, la degradación surte efecto en su siguiente clic. Por eso este enum
 * es el cast de `users.role` y no hay ninguna copia del rol en ningún otro
 * sitio.
 */
enum OrganizationRole: string
{
    /** Dueño: gobierna el negocio además de operarlo. */
    case Owner = 'owner';

    /** Empleado: opera el día a día y no toca el gobierno del negocio. */
    case Member = 'member';

    /**
     * Indica si el rol ejerce esa capacidad.
     *
     * Es la única regla del modelo, y por eso está escrita una sola vez: el
     * `owner` lo puede todo; el `member`, todo lo que no sea gobierno.
     */
    public function can(OrganizationCapability $capability): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Member => ! $capability->requiresOwner(),
        };
    }

    /**
     * Áreas que el rol ejerce, para pintar el menú sin repetir la frontera.
     *
     * Ocultar una sección **no** es control de acceso (RB-4): esto es lo que
     * necesita la navegación para no ofrecer lo que va a dar `403`, y la
     * policy del servidor es lo que impide llegar escribiendo la URL a mano.
     * Hacen falta las dos y no se sustituyen.
     *
     * @return list<OrganizationCapability>
     */
    public function capabilities(): array
    {
        return array_values(array_filter(
            OrganizationCapability::cases(),
            fn (OrganizationCapability $capability): bool => $this->can($capability),
        ));
    }

    /**
     * Gobierna el dinero que el negocio paga por la herramienta: el plan que
     * tiene contratado y las facturas de esa suscripción.
     *
     * No es la cobranza a sus clientes —eso es `Collections`, y la opera
     * también el `member`—. Confundir las dos es el error que dejaría a un
     * empleado sin poder hacer su trabajo o con acceso a la tarjeta del jefe.
     */
    public function canManageBilling(): bool
    {
        return $this->can(OrganizationCapability::Subscription)
            && $this->can(OrganizationCapability::Billing);
    }

    /**
     * Gobierna quién entra al backoffice: invitar, cambiar de rol, desactivar
     * y reactivar (`REQ-ORG-004`, `REQ-ORG-005`).
     */
    public function canManageTeam(): bool
    {
        return $this->can(OrganizationCapability::Team);
    }

    /**
     * Gobierna cómo opera y cómo se ve el negocio: ajustes, cuentas donde
     * recibe el dinero e identidad de la vitrina.
     *
     * Las cuentas de cobro están aquí y no en la operación diaria porque
     * cambiar dónde entra el dinero es la jugada más rentable para quien
     * consiga una sesión ajena: el empleado cobra, pero no decide a qué cuenta
     * va lo cobrado.
     */
    public function canManageSettings(): bool
    {
        return $this->can(OrganizationCapability::Settings)
            && $this->can(OrganizationCapability::PaymentAccounts)
            && $this->can(OrganizationCapability::Branding);
    }
}
