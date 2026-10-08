import { type OrganizationCapability } from '@/types/backoffice';
import { Link, usePage } from '@inertiajs/react';

/**
 * Navegación del backoffice filtrada por capacidad (`REQ-ORG-003` RB-4).
 *
 * Vive aparte del marco por una razón práctica: aquí es donde se va a añadir
 * una sección cada vez que llegue un hito, y el archivo que más cambia es el
 * que peor conviene tener mezclado con el resto del esqueleto.
 *
 * **Cada sección declara la capacidad que necesita, no el rol que la ve.** La
 * diferencia parece de estilo y no lo es: el reparto de RB-1 —qué es gobierno
 * y qué es operación diaria— vive en `OrganizationCapability::requiresOwner()`
 * y llega resuelto en `teamMember.capabilities`. Con un `ownerOnly` por
 * sección, como estaba hasta `T-014`, la frontera quedaba escrita dos veces y
 * el día que una capacidad cambiara de lado el enum lo sabría y el menú no
 * —sin un solo test en rojo, y con un `member` viendo una puerta que le va a
 * dar `403`—.
 *
 * **Ocultar no es control de acceso.** La policy del servidor es la que
 * protege; esto solo evita ofrecer lo que va a responder `403`.
 */

/**
 * Secciones que no son ninguna de las doce áreas, como el propio panel.
 *
 * Es un valor declarado y no un campo ausente a propósito: con `capability`
 * opcional, olvidarla haría pública una sección de gobierno **en silencio**,
 * que es exactamente el modo de fallo que este archivo viene a cerrar. Así
 * hay que escribir `'any'` para que la vea todo el mundo, y no escribir nada
 * no compila.
 */
export const ANY_CAPABILITY = 'any';

export interface NavSection {
    readonly label: string;
    readonly href: string;
    /** Área de `REQ-ORG-003` RB-1 que hay que ejercer para verla. */
    readonly capability: OrganizationCapability | typeof ANY_CAPABILITY;
}

interface BackofficeNavProps {
    sections: readonly NavSection[];
    capabilities: readonly OrganizationCapability[];
    /**
     * Una cifra junto a una sección, por su `href`: hoy, los comprobantes que
     * esperan (`REQ-PAY-004` RB-3). Un cero no se pinta: el badge avisa de
     * trabajo, y sin trabajo no hay nada que avisar.
     */
    badges?: Readonly<Record<string, number>>;
}

export function BackofficeNav({ sections, capabilities, badges = {} }: BackofficeNavProps) {
    const visible = sections.filter((section) => section.capability === ANY_CAPABILITY || capabilities.includes(section.capability));
    const current = usePage().url;

    return (
        <nav aria-label="Secciones del backoffice" className="bg-base-100 border-base-300 border-b px-2">
            <ul className="menu menu-horizontal gap-1 px-2 py-1">
                {visible.map((section) => (
                    <li key={section.href}>
                        <Link
                            href={section.href}
                            aria-current={current === section.href ? 'page' : undefined}
                            className={current === section.href ? 'menu-active' : undefined}
                        >
                            {section.label}
                            {(badges[section.href] ?? 0) > 0 ? ' ' : null}
                            {(badges[section.href] ?? 0) > 0 ? (
                                <span className="badge badge-sm badge-primary">
                                    {badges[section.href]} <span className="sr-only">pendientes</span>
                                </span>
                            ) : null}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
