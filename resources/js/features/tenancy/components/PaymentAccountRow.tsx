import { Badge } from '@/components/ui/badge';
import { BankMark } from '@/components/ui/bank-mark';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { cn } from '@/lib/utils';
import { type PaymentAccountView, type PaymentMethod } from '@/types/payment';
import { cilBank, cilDollar, cilMobile, cilMoney, cilPencil, cilTrash, cilWallet } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useState } from 'react';

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
    const [toggling, setToggling] = useState(false);
    const [confirming, setConfirming] = useState(false);

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
            { preserveScroll: true, onStart: () => setToggling(true), onFinish: () => setToggling(false) },
        );

    const cannotActivate = !account.isActive && atLimit;

    return (
        <li
            className={cn(
                'bg-base-100 border-line shadow-card flex flex-col gap-3 rounded-2xl border p-4 sm:flex-row sm:items-start sm:gap-3.5',
                !account.isActive && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3.5">
                <IconChip icon={METHOD_ICONS[account.method]} tone={account.isActive ? 'primary' : 'neutral'} size={40} />

                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="text-[15px] font-bold">{account.methodLabel}</span>
                        {account.bank ? (
                            <span className="text-ink-3 flex items-center gap-1.5 text-[13px]">
                                <BankMark bank={account.bank} logoUrl={account.bankLogoUrl} />
                                {account.bank}
                            </span>
                        ) : null}
                        <Badge tone={account.isActive ? 'success' : 'neutral'}>{account.isActive ? 'Activa' : 'Desactivada'}</Badge>
                    </p>
                    <p className="text-[15px] font-semibold tracking-wide break-all">{account.identifier}</p>
                    <p className="text-ink-3 text-xs break-words">
                        {account.identifierLabel} · {account.holderName} · {account.holderDocument}
                    </p>
                    {account.instructions ? (
                        <p className="bg-surface-2 text-ink-2 mt-1.5 self-start rounded-lg px-2.5 py-1 text-xs">{account.instructions}</p>
                    ) : null}
                </div>
            </div>

            <div className="border-line flex items-center justify-between gap-2 border-t pt-3 sm:shrink-0 sm:flex-col sm:items-end sm:border-0 sm:pt-0">
                <label
                    className="flex min-h-11 cursor-pointer items-center gap-2 text-[13px] font-medium"
                    title={cannotActivate ? 'Tu plan no admite otra cuenta activa.' : undefined}
                >
                    <input
                        type="checkbox"
                        className="toggle toggle-sm"
                        checked={account.isActive}
                        onChange={toggle}
                        disabled={cannotActivate || toggling}
                    />
                    <span className="sm:sr-only">Disponible</span>
                </label>
                <div className="flex gap-1">
                    <Button variant="ghost" onClick={onEdit} className="min-h-11">
                        <Icon icon={cilPencil} size={14} />
                        Editar
                    </Button>
                    <Button variant="ghost" onClick={() => setConfirming(true)} className="text-error min-h-11">
                        <Icon icon={cilTrash} size={14} />
                        Eliminar
                    </Button>
                </div>
            </div>

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                title={`¿Eliminar la cuenta de ${account.methodLabel}?`}
                description="Tus clientes dejarán de verla al apartar y al reportar un pago. Si ya tiene comprobantes, se desactiva en vez de borrarse para no perder ese rastro."
                confirmLabel="Eliminar cuenta"
                url={url}
            />
        </li>
    );
}
