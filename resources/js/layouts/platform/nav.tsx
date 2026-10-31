import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilBuilding, cilDescription, cilEnvelopeClosed, cilHistory, cilSettings, cilShieldAlt, cilSpeedometer } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export interface PlatformSection {
    readonly label: string;
    readonly short: string;
    readonly href: string;
    readonly icon: string[];
}

export const PLATFORM_SECTIONS: readonly PlatformSection[] = [
    { label: 'Resumen', short: 'Resumen', href: '/plataforma', icon: cilSpeedometer },
    { label: 'Organizaciones', short: 'Orgs', href: '/plataforma/organizaciones', icon: cilBuilding },
    { label: 'Comprobantes', short: 'Comprobantes', href: '/plataforma/facturas', icon: cilDescription },
    { label: 'Correos', short: 'Correos', href: '/plataforma/correos', icon: cilEnvelopeClosed },
];

export const PLATFORM_SUPPORT_SECTIONS: readonly PlatformSection[] = [
    { label: 'Accesos de soporte', short: 'Accesos', href: '/plataforma/soporte', icon: cilShieldAlt },
    { label: 'Auditoría', short: 'Auditoría', href: '/plataforma/auditoria', icon: cilHistory },
    { label: 'Ajustes', short: 'Ajustes', href: '/plataforma/ajustes', icon: cilSettings },
];

interface OperatorCounts {
    pendingReceipts: number;
    activeSupportGrants: number;
    unreadMail?: number;
}

export function isCurrentPlatformSection(href: string, url: string): boolean {
    const path = url.split('?')[0] ?? url;

    return href === '/plataforma' ? path === href : path === href || path.startsWith(`${href}/`);
}

function NavList({ sections, counts, current }: { sections: readonly PlatformSection[]; counts: Record<string, number>; current: string }) {
    return (
        <ul className="flex flex-col gap-0.5">
            {sections.map((section) => {
                const active = isCurrentPlatformSection(section.href, current);

                return (
                    <li key={section.href}>
                        <Link
                            href={section.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex h-[38px] items-center gap-3 rounded-[10px] px-3 text-sm transition-colors',
                                active
                                    ? 'bg-primary-soft text-primary-ink font-bold'
                                    : 'text-ink-2 hover:bg-surface-2 hover:text-base-content font-medium',
                            )}
                        >
                            <Icon icon={section.icon} size={18} />
                            <span className="flex-1 truncate">{section.label}</span>
                            {(counts[section.href] ?? 0) > 0 ? (
                                <span className="bg-surface-2 text-ink-2 rounded-full px-[7px] py-px text-[11px] font-bold">
                                    {counts[section.href]}
                                </span>
                            ) : null}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

export function PlatformNav() {
    const { url: current, props } = usePage<{ platformOperator?: OperatorCounts | null }>();
    const counts: Record<string, number> = {
        '/plataforma/facturas': props.platformOperator?.pendingReceipts ?? 0,
        '/plataforma/soporte': props.platformOperator?.activeSupportGrants ?? 0,
        '/plataforma/correos': props.platformOperator?.unreadMail ?? 0,
    };

    return (
        <nav aria-label="Plataforma" className="flex flex-col gap-5">
            <NavList sections={PLATFORM_SECTIONS} counts={counts} current={current} />
            <div className="flex flex-col gap-1.5">
                <p className="text-ink-3 px-3 text-[11px] font-bold tracking-[0.06em] uppercase">Soporte</p>
                <NavList sections={PLATFORM_SUPPORT_SECTIONS} counts={counts} current={current} />
            </div>
        </nav>
    );
}

export function PlatformSupportTabs() {
    const current = usePage().url;

    return (
        <nav aria-label="Soporte" className="bg-surface-2 flex gap-0.5 self-start rounded-[10px] p-[3px] lg:hidden">
            {PLATFORM_SUPPORT_SECTIONS.map((section) => {
                const active = isCurrentPlatformSection(section.href, current);

                return (
                    <Link
                        key={section.href}
                        href={section.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            'rounded-lg px-3.5 py-2 text-[13px]',
                            active ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                        )}
                    >
                        {section.short}
                    </Link>
                );
            })}
        </nav>
    );
}

const TAB_BAR_SECTIONS: readonly (PlatformSection & { matches: readonly string[] })[] = [
    ...PLATFORM_SECTIONS.map((section) => ({ ...section, matches: [section.href] })),
    {
        label: 'Soporte',
        short: 'Soporte',
        href: '/plataforma/soporte',
        icon: cilShieldAlt,
        matches: PLATFORM_SUPPORT_SECTIONS.map((section) => section.href),
    },
];

export function PlatformTabBar() {
    const current = usePage().url;

    return (
        <nav
            aria-label="Accesos rápidos"
            className="bg-base-100 border-line fixed inset-x-0 bottom-0 z-40 flex border-t px-2 pt-2 pb-[max(env(safe-area-inset-bottom),0.75rem)] lg:hidden"
        >
            {TAB_BAR_SECTIONS.map((section) => {
                const active = section.matches.some((href) => isCurrentPlatformSection(href, current));

                return (
                    <Link
                        key={section.href}
                        href={section.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn('flex flex-1 flex-col items-center gap-1 py-1.5', active ? 'text-primary-ink' : 'text-ink-3')}
                    >
                        <span className={cn('flex h-7 w-12 items-center justify-center rounded-full', active && 'bg-primary-soft')}>
                            <Icon icon={section.icon} size={20} />
                        </span>
                        <span className={cn('text-[11px] leading-4', active ? 'font-bold' : 'font-medium')}>{section.short}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
