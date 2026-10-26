import { menuItemClass } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type PlatformAlert } from '@/types/platform';
import { cilBan, cilBell, cilClock, cilDescription, cilShieldAlt, cilWarning } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';

const COPY: Record<string, { icon: string[]; label: (count: number) => string }> = {
    pendingReceipts: { icon: cilDescription, label: (count) => (count === 1 ? 'comprobante por revisar' : 'comprobantes por revisar') },
    lateReceipts: {
        icon: cilClock,
        label: (count) => (count === 1 ? 'comprobante lleva más de 48 h esperando' : 'comprobantes llevan más de 48 h esperando'),
    },
    graceEnding: {
        icon: cilBan,
        label: (count) => (count === 1 ? 'negocio se suspende en menos de 3 días' : 'negocios se suspenden en menos de 3 días'),
    },
    overdueInvoices: { icon: cilWarning, label: (count) => (count === 1 ? 'negocio con facturas vencidas' : 'negocios con facturas vencidas') },
    myOpenSupport: {
        icon: cilShieldAlt,
        label: (count) => (count === 1 ? 'acceso de soporte tuyo sigue abierto' : 'accesos de soporte tuyos siguen abiertos'),
    },
    failedJobs: { icon: cilWarning, label: (count) => (count === 1 ? 'trabajo fallido en la cola' : 'trabajos fallidos en la cola') },
};

const TONES: Record<string, string> = { error: 'text-error', warning: 'text-warning', info: 'text-primary' };

export function PlatformBell({ className }: { className?: string }) {
    const operator = usePage<{ platformOperator?: { pendingReceipts: number; alerts?: readonly PlatformAlert[] } | null }>().props.platformOperator;
    const pending = operator?.pendingReceipts ?? 0;
    const items: readonly PlatformAlert[] = [
        ...(pending > 0 ? [{ key: 'pendingReceipts', count: pending, tone: 'info', href: '/plataforma/facturas' }] : []),
        ...(operator?.alerts ?? []),
    ];
    const urgent = items.filter((item) => item.tone !== 'info').length;
    const total = urgent + (pending > 0 ? 1 : 0);

    return (
        <Menu as="div" className={cn('relative', className)}>
            <MenuButton
                className="border-line hover:bg-surface-2 relative flex size-10 cursor-pointer items-center justify-center rounded-[10px] border"
                aria-label={items.length > 0 ? `Avisos: ${items.length}` : 'Sin avisos'}
            >
                <Icon icon={cilBell} size={18} />
                {total > 0 ? (
                    <span
                        className={cn(
                            'absolute -top-1.5 -right-1.5 min-w-[18px] rounded-full px-1 text-center text-[11px] leading-[18px] font-bold',
                            urgent > 0 ? 'bg-error text-error-content' : 'bg-primary text-primary-content',
                        )}
                    >
                        {total}
                    </span>
                ) : null}
            </MenuButton>

            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-2 w-80 rounded-xl border p-1.5 focus:outline-none">
                <p className="text-eyebrow text-ink-3 px-2.5 pt-2 pb-1">Para atender</p>
                {items.map((item) => {
                    const copy = COPY[item.key];

                    return copy ? (
                        <MenuItem key={item.key}>
                            <Link href={item.href} className={menuItemClass}>
                                <Icon icon={copy.icon} className={TONES[item.tone]} />
                                <span className="flex-1">
                                    <strong className="text-base-content">{item.count}</strong> {copy.label(item.count)}
                                </span>
                            </Link>
                        </MenuItem>
                    ) : null;
                })}
                {items.length === 0 ? <p className="text-ink-3 px-2.5 py-2 text-sm">Nada pendiente. Todo al día.</p> : null}
            </MenuItems>
        </Menu>
    );
}
