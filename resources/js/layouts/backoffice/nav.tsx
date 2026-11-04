import { Icon } from '@/components/ui/icon';
import { bo } from '@/lib/surfaces';
import { cn } from '@/lib/utils';
import { type OrganizationCapability } from '@/types/backoffice';
import { cilChevronBottom } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export const ANY_CAPABILITY = 'any';

export interface NavSection {
    readonly label: string;
    readonly href: string;
    readonly capability: OrganizationCapability | typeof ANY_CAPABILITY;
    readonly icon?: string[];
    readonly group?: string;
}

export function sectionPath(href: string): string {
    return href.split('?')[0] ?? href;
}

export function isCurrentSection(href: string, url: string): boolean {
    const path = url.split('?')[0] ?? url;
    const target = sectionPath(href);

    return target === bo('') ? path === target : path === target || path.startsWith(`${target}/`);
}

interface BackofficeNavProps {
    sections: readonly NavSection[];
    capabilities: readonly OrganizationCapability[];
    badges?: Readonly<Record<string, number>>;
}

function NavLink({ section, current, badge }: { section: NavSection; current: string; badge: number }) {
    const active = isCurrentSection(section.href, current);

    return (
        <li>
            <Link
                href={section.href}
                aria-current={active ? 'page' : undefined}
                className={cn(
                    'touch:h-11 flex h-10 items-center gap-3 rounded-md px-3 text-[0.9375rem] transition-colors',
                    active ? 'bg-neutral text-neutral-content font-bold' : 'text-base-content lg:hover:bg-surface-2 font-semibold',
                )}
            >
                {section.icon ? <Icon icon={section.icon} size={18} className={active ? '' : 'text-ink-3'} /> : null}
                <span className="flex-1 truncate">{section.label}</span>
                {badge > 0 ? ' ' : null}
                {badge > 0 ? (
                    <span className={cn('badge badge-count', active ? 'bg-neutral-content text-neutral border-0' : 'badge-primary')}>
                        {badge} <span className="sr-only">{badge === 1 ? 'pendiente' : 'pendientes'}</span>
                    </span>
                ) : null}
            </Link>
        </li>
    );
}

export function BackofficeNav({ sections, capabilities, badges = {} }: BackofficeNavProps) {
    const visible = sections.filter((section) => section.capability === ANY_CAPABILITY || capabilities.includes(section.capability));
    const current = usePage().url;
    const main = visible.filter((section) => section.group === undefined);
    const rest = visible.filter((section) => section.group !== undefined);

    const groups: { label: string; items: NavSection[] }[] = [];
    for (const section of rest) {
        const last = groups[groups.length - 1];
        if (last !== undefined && last.label === section.group) {
            last.items.push(section);
        } else {
            groups.push({ label: section.group ?? '', items: [section] });
        }
    }

    const insideMore = rest.some((section) => isCurrentSection(section.href, current));

    return (
        <nav aria-label="Secciones del backoffice" className="flex flex-col gap-2">
            <ul className="flex flex-col gap-0.5">
                {main.map((section) => (
                    <NavLink key={section.href} section={section} current={current} badge={badges[sectionPath(section.href)] ?? 0} />
                ))}
            </ul>

            {groups.length > 0 ? (
                <details className="group" open={insideMore}>
                    <summary className="text-ink-2 hover:bg-surface-2 touch:h-11 flex h-10 cursor-pointer list-none items-center gap-3 rounded-md px-3 text-[0.9375rem] font-semibold [&::-webkit-details-marker]:hidden">
                        <span className="flex-1">Más</span>
                        <Icon icon={cilChevronBottom} size={14} className="transition-transform group-open:rotate-180" />
                    </summary>
                    <div className="mt-2 flex flex-col gap-4">
                        {groups.map((group) => (
                            <div key={group.label} className="flex flex-col gap-0.5">
                                <p className="font-hand text-ink-3 px-3 pb-0.5 text-lg leading-none">{group.label}</p>
                                <ul className="flex flex-col gap-0.5">
                                    {group.items.map((section) => (
                                        <NavLink
                                            key={section.href}
                                            section={section}
                                            current={current}
                                            badge={badges[sectionPath(section.href)] ?? 0}
                                        />
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </details>
            ) : null}
        </nav>
    );
}
