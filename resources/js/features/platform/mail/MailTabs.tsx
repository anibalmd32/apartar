import { Icon } from '@/components/ui/icon';
import { isCurrentPlatformSection } from '@/layouts/platform/nav';
import { cn } from '@/lib/utils';
import { cilPencil } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

export const MAIL_TABS = [
    { label: 'Métricas', href: '/plataforma/correos', exact: true },
    { label: 'Enviados', href: '/plataforma/correos/enviados', exact: false },
    { label: 'Bandeja', href: '/plataforma/correos/bandeja', exact: false },
] as const;

export function isCurrentMailTab(href: string, exact: boolean, url: string): boolean {
    const path = url.split('?')[0] ?? url;

    return exact ? path === href : isCurrentPlatformSection(href, url);
}

export function MailTabs() {
    const { url, props } = usePage<{ platformOperator?: { unreadMail?: number } | null }>();
    const unread = props.platformOperator?.unreadMail ?? 0;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <nav aria-label="Correos" className="bg-surface-2 flex gap-0.5 self-start rounded-[10px] p-[3px]">
                {MAIL_TABS.map((tab) => {
                    const active = isCurrentMailTab(tab.href, tab.exact, url);

                    return (
                        <Link
                            key={tab.href}
                            href={tab.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-[13px]',
                                active ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                            )}
                        >
                            {tab.label}
                            {tab.label === 'Bandeja' && unread > 0 ? (
                                <span className="badge badge-count badge-solid badge-primary">
                                    {unread}
                                    <span className="sr-only"> sin leer</span>
                                </span>
                            ) : null}
                        </Link>
                    );
                })}
            </nav>
            <Link href="/plataforma/correos/redactar" className="btn btn-primary btn-sm">
                <Icon icon={cilPencil} size={14} />
                Redactar
            </Link>
        </div>
    );
}
