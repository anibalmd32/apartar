import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilChatBubble, cilHome, cilLibrary, cilStar } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

interface PortalNavItem {
    href: string;
    label: string;
    short: string;
    icon: string[];
    badge?: number;
}

export function portalNavItems(isAccount: boolean, unreadChats: number): PortalNavItem[] {
    return [
        { href: route('portal.dashboard'), label: 'Inicio', short: 'Inicio', icon: cilHome },
        ...(isAccount ? [{ href: route('portal.feed'), label: 'Novedades', short: 'Novedades', icon: cilStar }] : []),
        ...(isAccount ? [{ href: route('portal.subscriptions'), label: 'Catálogos que sigo', short: 'Catálogos', icon: cilLibrary }] : []),
        { href: route('portal.chat.index'), label: 'Chat', short: 'Chat', icon: cilChatBubble, badge: unreadChats },
    ];
}

function useIsCurrent() {
    const url = usePage().url ?? '';
    const path = url.split('?')[0] ?? '';

    return (href: string) => {
        const target = new URL(href, 'http://x').pathname.replace(/\/$/, '');
        const current = path.replace(/\/$/, '');

        if (target.endsWith('/mi-cuenta')) {
            return current === target || current.startsWith(`${target}/apartados/`);
        }

        return current === target || current.startsWith(`${target}/`);
    };
}

function UnreadBadge({ count, className }: { count: number; className?: string }) {
    return (
        <span className={cn('bg-error text-error-content rounded-full px-[5px] text-[10px] leading-[14px] font-bold', className)}>
            {count}
            <span className="sr-only"> sin leer</span>
        </span>
    );
}

export function PortalNav({ items }: { items: PortalNavItem[] }) {
    const isCurrent = useIsCurrent();

    return (
        <nav aria-label="Secciones de tu cuenta" className="flex items-center gap-1">
            {items.map((item) => {
                const active = isCurrent(item.href);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'flex h-9 items-center gap-2 rounded-[10px] px-3 text-sm transition-colors',
                            active ? 'bg-primary-soft text-primary-ink font-bold' : 'text-ink-2 hover:bg-surface-2 font-medium',
                        )}
                    >
                        <Icon icon={item.icon} />
                        {item.label}
                        {item.badge ? <UnreadBadge count={item.badge} /> : null}
                    </Link>
                );
            })}
        </nav>
    );
}

export function PortalTabBar({ items }: { items: PortalNavItem[] }) {
    const isCurrent = useIsCurrent();

    return (
        <nav
            aria-label="Accesos rápidos"
            className="bg-base-100 border-line fixed inset-x-0 bottom-0 z-40 flex border-t px-2 pt-2 pb-[max(env(safe-area-inset-bottom),0.75rem)] lg:hidden"
        >
            {items.map((item) => {
                const active = isCurrent(item.href);

                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn('flex flex-1 flex-col items-center gap-1 py-1.5', active ? 'text-primary-ink' : 'text-ink-3')}
                    >
                        <span className={cn('relative flex h-7 w-12 items-center justify-center rounded-full', active && 'bg-primary-soft')}>
                            <Icon icon={item.icon} size={20} />
                            {item.badge ? <UnreadBadge count={item.badge} className="absolute -top-1.5 left-7" /> : null}
                        </span>
                        <span className={cn('text-[11px] leading-4', active ? 'font-bold' : 'font-medium')}>{item.short}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
