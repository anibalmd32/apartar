import { type ReactNode } from 'react';

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
        case 'catalog.follow':
        case 'catalog.unfollow':
            return `/c/${params.catalog}/seguir`;
        case 'portal.claim':
        case 'portal.claim.store':
            return '/mi-cuenta/reclamar';
        case 'portal.chat.index':
            return '/mi-cuenta/chat';
        case 'portal.feed':
            return '/mi-cuenta/novedades';
        case 'portal.subscriptions':
            return '/mi-cuenta/suscripciones';
        case 'portal.subscriptions.update':
            return `/mi-cuenta/suscripciones/${params.catalog}`;
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
        case 'portal.receipts.create':
        case 'portal.receipts.store':
            return `/mi-cuenta/apartados/${params.code}/comprobante`;
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

export function FakeLink({ href, className, children }: FakeLinkProps) {
    return (
        <a href={href} className={className}>
            {children}
        </a>
    );
}
