import { menuItemClass } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { type PastDueNotice } from '@/features/plan/components/PastDueBanner';
import { cn } from '@/lib/utils';
import { type BackofficeSharedData } from '@/types/backoffice';
import { cilBell, cilChatBubble, cilDescription, cilWarning } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';

interface Attention {
    readonly key: string;
    readonly label: string;
    readonly href: string;
    readonly icon: string[];
    readonly count: number;
}

export function AttentionBell({ pendingReceipts, className }: { pendingReceipts: number | null; className?: string }) {
    const { teamMember, pastDue } = usePage<BackofficeSharedData & { pastDue?: PastDueNotice | null }>().props;

    const items: Attention[] = [
        { key: 'receipts', label: 'comprobantes por revisar', href: '/backoffice/comprobantes', icon: cilDescription, count: pendingReceipts ?? 0 },
        { key: 'chats', label: 'conversaciones sin leer', href: '/backoffice/chat', icon: cilChatBubble, count: teamMember?.unreadChats ?? 0 },
    ].filter((item) => item.count > 0);

    const total = items.reduce((sum, item) => sum + item.count, 0) + (pastDue ? 1 : 0);

    return (
        <Menu as="div" className={cn('relative', className)}>
            <MenuButton
                className="border-line hover:bg-surface-2 relative flex size-10 cursor-pointer items-center justify-center rounded-[10px] border"
                aria-label={total > 0 ? `Pendientes: ${total}` : 'Sin pendientes'}
            >
                <Icon icon={cilBell} size={18} />
                {total > 0 ? (
                    <span className="bg-error text-error-content absolute -top-1.5 -right-1.5 min-w-[18px] rounded-full px-1 text-center text-[11px] leading-[18px] font-bold">
                        {total > 99 ? '99+' : total}
                    </span>
                ) : null}
            </MenuButton>

            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-2 w-72 rounded-xl border p-1.5 focus:outline-none">
                <p className="text-eyebrow text-ink-3 px-2.5 pt-2 pb-1">Para atender</p>
                {pastDue ? (
                    <MenuItem>
                        <Link href={pastDue.invoicesUrl ?? '/backoffice/suscripcion'} className={menuItemClass}>
                            <Icon icon={cilWarning} className="text-error" />
                            <span className="flex-1">Tienes una factura vencida{pastDue.amount ? ` (${pastDue.amount})` : ''}</span>
                        </Link>
                    </MenuItem>
                ) : null}
                {items.map((item) => (
                    <MenuItem key={item.key}>
                        <Link href={item.href} className={menuItemClass}>
                            <Icon icon={item.icon} />
                            <span className="flex-1">
                                <strong className="text-base-content">{item.count}</strong> {item.label}
                            </span>
                        </Link>
                    </MenuItem>
                ))}
                {total === 0 ? <p className="text-ink-3 px-2.5 py-2 text-sm">Nada pendiente. Todo al día.</p> : null}
            </MenuItems>
        </Menu>
    );
}
