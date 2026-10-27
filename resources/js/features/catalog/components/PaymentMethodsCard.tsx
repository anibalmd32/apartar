import { BankMark } from '@/components/ui/bank-mark';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { CopyButton } from '@/features/catalog/components/CopyButton';
import { type PaymentAccountView, type PaymentMethod } from '@/types/payment';
import { cilBank, cilDollar, cilMobile, cilMoney, cilWallet } from '@coreui/icons';

export const PAYMENT_METHOD_ICONS: Record<PaymentMethod, string[]> = {
    pago_movil: cilMobile,
    transferencia: cilBank,
    zelle: cilDollar,
    binance_pay: cilDollar,
    efectivo_usd: cilMoney,
    efectivo_ves: cilMoney,
};

interface PaymentMethodsCardProps {
    accounts: readonly PaymentAccountView[];
    businessName: string;
}

export function PaymentMethodsCard({ accounts, businessName }: PaymentMethodsCardProps) {
    return (
        <section aria-label="Formas de pago" className="bg-surface-2 flex flex-col gap-2.5 rounded-[14px] p-4">
            <h2 className="flex items-center gap-2 text-sm font-bold">
                <Icon icon={cilWallet} />
                Cómo pagarle a {businessName}
            </h2>

            {accounts.length === 0 ? (
                <p className="text-ink-2 text-[13px]">
                    Consulta a {businessName} por el chat cómo pagarle: todavía no ha publicado sus formas de pago.
                </p>
            ) : (
                <ul className="flex flex-col gap-2.5">
                    {accounts.map((account) => (
                        <li key={account.id} className="bg-base-100 flex items-start gap-2.5 rounded-[10px] px-3 py-2.5">
                            <IconChip icon={PAYMENT_METHOD_ICONS[account.method]} />
                            <div className="flex min-w-0 flex-1 flex-col gap-px">
                                <p className="flex flex-wrap items-center gap-x-1.5 text-[13px] font-semibold">
                                    {account.methodLabel}
                                    {account.bank !== null ? (
                                        <>
                                            <span aria-hidden="true">·</span>
                                            <BankMark bank={account.bank} logoUrl={account.bankLogoUrl} className="h-4" />
                                            <span>{account.bank}</span>
                                        </>
                                    ) : null}
                                </p>
                                <p className="text-ink-3 text-xs break-words">
                                    <span className="sr-only">{account.identifierLabel}: </span>
                                    <span className="text-ink-2 font-medium select-all">{account.identifier}</span>
                                    {' · '}
                                    {account.holderName} · {account.holderDocument}
                                </p>
                                {account.instructions !== null ? <p className="text-ink-3 text-xs">{account.instructions}</p> : null}
                            </div>
                            <CopyButton value={account.identifier} label={`${account.identifierLabel.toLowerCase()} de ${account.methodLabel}`} />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
