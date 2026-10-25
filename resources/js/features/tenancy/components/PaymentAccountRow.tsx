import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { cn } from '@/lib/utils';
import { type PaymentAccountView, type PaymentMethod } from '@/types/payment';
import { cilBank, cilDollar, cilMobile, cilMoney, cilPencil, cilWallet } from '@coreui/icons';
import { router } from '@inertiajs/react';

const METHOD_ICONS: Record<PaymentMethod, string[]> = {
    pago_movil: cilMobile,
    transferencia: cilBank,
    zelle: cilDollar,
    binance_pay: cilWallet,
    efectivo_usd: cilMoney,
    efectivo_ves: cilMoney,
};

interface PaymentAccountRowProps {
    account: PaymentAccountView;
    atLimit: boolean;
    onEdit: () => void;
}

export function PaymentAccountRow({ account, atLimit, onEdit }: PaymentAccountRowProps) {
    const url = `/backoffice/cuentas-de-cobro/${account.id}`;

    const toggle = () =>
        router.put(
            url,
            {
                method: account.method,
                bank: account.bank ?? '',
                holder_name: account.holderName,
                holder_document: account.holderDocument,
                identifier: account.identifier,
                instructions: account.instructions ?? '',
                is_active: !account.isActive,
            },
            { preserveScroll: true },
        );

    const remove = () => router.delete(url, { preserveScroll: true });

    const cannotActivate = !account.isActive && atLimit;

    return (
        <li className={cn('bg-base-100 border-line shadow-card flex items-start gap-3.5 rounded-2xl border p-4', !account.isActive && 'opacity-70')}>
            <IconChip icon={METHOD_ICONS[account.method]} tone={account.isActive ? 'primary' : 'neutral'} size={40} />

            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-[15px] font-bold">{account.methodLabel}</span>
                    {account.bank ? <span className="text-ink-3 text-[13px]">· {account.bank}</span> : null}
                    <Badge tone={account.isActive ? 'success' : 'neutral'}>{account.isActive ? 'Activa' : 'Desactivada'}</Badge>
                </p>
                <p className="text-[15px] font-semibold tracking-wide">{account.identifier}</p>
                <p className="text-ink-3 text-xs">
                    {account.identifierLabel} · {account.holderName} · {account.holderDocument}
                </p>
                {account.instructions ? (
                    <p className="bg-surface-2 text-ink-2 mt-1.5 self-start rounded-lg px-2.5 py-1 text-xs">{account.instructions}</p>
                ) : null}
            </div>

            <div className="flex shrink-0 flex-col items-end gap-1">
                <label
                    className="flex cursor-pointer items-center gap-2"
                    title={cannotActivate ? 'Tu plan no admite otra cuenta activa.' : undefined}
                >
                    <span className="sr-only">{account.isActive ? 'Desactivar' : 'Activar'}</span>
                    <input type="checkbox" className="toggle toggle-sm" checked={account.isActive} onChange={toggle} disabled={cannotActivate} />
                </label>
                <div className="flex gap-1">
                    <Button size="sm" variant="ghost" onClick={onEdit}>
                        <Icon icon={cilPencil} size={14} />
                        Editar
                    </Button>
                    <Button size="sm" variant="ghost" onClick={remove}>
                        Eliminar
                    </Button>
                </div>
            </div>
        </li>
    );
}
