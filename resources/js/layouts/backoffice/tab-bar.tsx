import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type OrganizationCapability } from '@/types/backoffice';
import { cilMenu } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';
import { ANY_CAPABILITY, isCurrentSection, type NavSection } from './nav';

interface TabBarProps {
    tabs: readonly NavSection[];
    capabilities: readonly OrganizationCapability[];
    badges?: Readonly<Record<string, number>>;
    onMore: () => void;
    moreOpen: boolean;
}

export function TabBar({ tabs, capabilities, badges = {}, onMore, moreOpen }: TabBarProps) {
    const current = usePage().url;
    const visible = tabs.filter((tab) => tab.capability === ANY_CAPABILITY || capabilities.includes(tab.capability));

    const item = (active: boolean, icon: string[] | undefined, label: string, badge = 0) => (
        <>
            <span className={cn('relative flex h-7 w-12 items-center justify-center rounded-full', active && 'bg-primary-soft')}>
                {icon ? <Icon icon={icon} size={20} /> : null}
                {badge > 0 ? (
                    <span className="bg-error text-error-content absolute -top-1.5 left-7 rounded-full px-[5px] text-[10px] leading-[14px] font-bold">
                        {badge}
                        <span className="sr-only"> pendientes</span>
                    </span>
                ) : null}
            </span>
            <span className={cn('text-[11px] leading-4', active ? 'font-bold' : 'font-medium')}>{label}</span>
        </>
    );

    return (
        <nav
            aria-label="Accesos rápidos"
            className="bg-base-100 border-line fixed inset-x-0 bottom-0 z-40 flex border-t px-2 pt-2 pb-[max(env(safe-area-inset-bottom),0.75rem)] lg:hidden"
        >
            {visible.map((tab) => {
                const active = isCurrentSection(tab.href, current);

                return (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn('flex flex-1 flex-col items-center gap-1 py-1.5', active ? 'text-primary-ink' : 'text-ink-3')}
                    >
                        {item(active, tab.icon, tab.label, badges[tab.href])}
                    </Link>
                );
            })}

            <button
                type="button"
                onClick={onMore}
                aria-expanded={moreOpen}
                className={cn('flex flex-1 flex-col items-center gap-1 py-1.5', moreOpen ? 'text-primary-ink' : 'text-ink-3')}
            >
                {item(moreOpen, cilMenu, 'Más')}
            </button>
        </nav>
    );
}
