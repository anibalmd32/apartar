import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type OrganizationCapability } from '@/types/backoffice';
import { Link, usePage } from '@inertiajs/react';

export const ANY_CAPABILITY = 'any';

export interface NavSection {
    readonly label: string;
    readonly href: string;
    readonly capability: OrganizationCapability | typeof ANY_CAPABILITY;
    readonly icon?: string[];
    readonly group?: string;
}

export function isCurrentSection(href: string, url: string): boolean {
    const path = url.split('?')[0] ?? url;

    return href === '/backoffice' ? path === href : path === href || path.startsWith(`${href}/`);
}

interface BackofficeNavProps {
    sections: readonly NavSection[];
    capabilities: readonly OrganizationCapability[];
    badges?: Readonly<Record<string, number>>;
}

export function BackofficeNav({ sections, capabilities, badges = {} }: BackofficeNavProps) {
    const visible = sections.filter((section) => section.capability === ANY_CAPABILITY || capabilities.includes(section.capability));
    const current = usePage().url;

    const groups: { label: string | undefined; items: NavSection[] }[] = [];
    for (const section of visible) {
        const last = groups[groups.length - 1];
        if (last !== undefined && last.label === section.group) {
            last.items.push(section);
        } else {
            groups.push({ label: section.group, items: [section] });
        }
    }

    return (
        <nav aria-label="Secciones del backoffice" className="flex flex-col gap-5">
            {groups.map((group) => (
                <div key={group.label ?? 'principal'} className="flex flex-col gap-0.5">
                    {group.label ? <p className="text-eyebrow text-ink-3 px-3 pb-1.5">{group.label}</p> : null}
                    <ul className="flex flex-col gap-0.5">
                        {group.items.map((section) => {
                            const active = isCurrentSection(section.href, current);
                            const badge = badges[section.href] ?? 0;

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
                                        {section.icon ? <Icon icon={section.icon} size={18} /> : null}
                                        <span className="flex-1 truncate">{section.label}</span>
                                        {badge > 0 ? ' ' : null}
                                        {badge > 0 ? (
                                            <span className={cn('badge badge-count', active ? 'badge-primary' : 'badge-neutral')}>
                                                {badge} <span className="sr-only">pendientes</span>
                                            </span>
                                        ) : null}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}
