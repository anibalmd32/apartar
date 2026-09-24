import { type ReactNode } from 'react';

/**
 * Dobles de lo que en la aplicación ponen Inertia y Ziggy, para los tests de
 * las páginas públicas del catálogo.
 *
 * `route()` es global en la aplicación porque `@routes` lo inyecta en la
 * plantilla con la lista real; en jsdom no hay plantilla. El doble conoce solo
 * las dos rutas públicas del catálogo, y con la misma forma que
 * `routes/catalog.php`: un test que pida otra falla en voz alta en lugar de
 * recibir una URL inventada.
 */

type RouteParams = Record<string, string>;

export function fakeRoute(name: string, params: RouteParams = {}): string {
    switch (name) {
        case 'catalog.show':
            return `/c/${params.catalog}`;
        case 'catalog.product':
            return `/c/${params.catalog}/p/${params.product}`;
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
