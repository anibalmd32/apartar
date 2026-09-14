import { type OrganizationRole } from '@/types/backoffice';
import { Link, usePage } from '@inertiajs/react';

/**
 * Navegación del backoffice filtrada por rol (`REQ-ORG-003` RB-4).
 *
 * Vive aparte del marco por una razón práctica: aquí es donde se va a añadir
 * una sección cada vez que llegue un hito, y el archivo que más cambia es el
 * que peor conviene tener mezclado con el resto del esqueleto.
 *
 * **Ocultar no es control de acceso.** La policy del servidor es la que
 * protege; esto solo evita ofrecerle a un `member` una puerta que le va a
 * responder `403`.
 */

export interface NavSection {
    readonly label: string;
    readonly href: string;
    /** Solo la ve el `owner`: suscripción, facturación, equipo, ajustes. */
    readonly ownerOnly: boolean;
}

interface BackofficeNavProps {
    sections: readonly NavSection[];
    role: OrganizationRole;
}

export function BackofficeNav({ sections, role }: BackofficeNavProps) {
    const visible = sections.filter((section) => !section.ownerOnly || role === 'owner');
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
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
