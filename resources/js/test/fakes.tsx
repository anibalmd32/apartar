import { type ReactNode } from 'react';

/**
 * Dobles de lo que en la aplicación ponen Inertia y Ziggy, para los tests de
 * las páginas públicas del catálogo.
 *
 * `route()` es global en la aplicación porque `@routes` lo inyecta en la
 * plantilla con la lista real; en jsdom no hay plantilla. El doble conoce solo
 * las rutas públicas del catálogo —la vista, la ficha y las tres del apartado—
 * y las del acceso al portal, con la misma forma que `routes/catalog.php` y
 * `routes/portal.php`: un test que pida otra falla en voz alta en lugar de
 * recibir una URL inventada.
 */

type RouteParams = Record<string, string>;

export function fakeRoute(name: string, params: RouteParams = {}): string {
    switch (name) {
        case 'catalog.show':
            return `/c/${params.catalog}`;
        case 'catalog.product':
            return `/c/${params.catalog}/p/${params.product}`;
        case 'catalog.layaway.create':
            return `/c/${params.catalog}/apartar/${params.product}`;
        case 'catalog.layaway.store':
            return `/c/${params.catalog}/apartar/${params.product}`;
        case 'catalog.layaway.thanks':
            return `/c/${params.catalog}/apartar/${params.code}/gracias`;
        case 'portal.access':
            return '/mi-cuenta/acceso';
        case 'portal.access.code':
            return '/mi-cuenta/acceso/codigo';
        case 'portal.logout':
            return '/mi-cuenta/salir';
        case 'portal.dashboard':
            return '/mi-cuenta';
        case 'portal.layaways.show':
            return `/mi-cuenta/apartados/${params.code}`;
        case 'portal.link.store':
            return '/mi-cuenta/acceso/enlace';
        case 'portal.link.show':
        case 'portal.link.consume':
            return `/mi-cuenta/acceso/enlace/${params.token}`;
        default:
            throw new Error(`fakeRoute no conoce la ruta «${name}».`);
    }
}

interface FakeLinkProps {
    href: string;
    className?: string;
    children: ReactNode;
}

/** El `<Link>` de Inertia como el `<a>` que acaba siendo. */
export function FakeLink({ href, className, children }: FakeLinkProps) {
    return (
        <a href={href} className={className}>
            {children}
        </a>
    );
}
