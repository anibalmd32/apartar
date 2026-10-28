import { BankMark } from '@/components/ui/bank-mark';
import { IconChip } from '@/components/ui/icon-chip';
import { CopyButton } from '@/features/catalog/components/CopyButton';
import { PAYMENT_METHOD_ICONS } from '@/features/catalog/components/PaymentMethodsCard';
import { type PaymentAccountView } from '@/types/payment';

interface PaymentAccountListProps {
    accounts: readonly PaymentAccountView[];
    businessName: string;
}

export function PaymentAccountList({ accounts, businessName }: PaymentAccountListProps) {
    if (accounts.length === 0) {
        return (
            <p className="text-ink-2 text-[13px]">Consulta a {businessName} por el chat cómo pagarle: todavía no ha publicado sus formas de pago.</p>
        );
    }

    return (
        <ul className="flex flex-col gap-2.5">
            {accounts.map((account) => (
                <li key={account.id} className="bg-base-200 flex items-start gap-2.5 rounded-[10px] px-3 py-2.5">
                    <IconChip icon={PAYMENT_METHOD_ICONS[account.method]} />
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-[13px]">
                        <p className="flex flex-wrap items-center gap-x-1.5 font-semibold">
                            {account.methodLabel}
                            {account.bank !== null ? (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <BankMark bank={account.bank} logoUrl={account.bankLogoUrl} />
                                    <span>{account.bank}</span>
                                </>
                            ) : null}
                        </p>
                        <p className="break-words">
                            <span className="text-ink-3">{account.identifierLabel}:</span>{' '}
                            <span className="font-semibold tracking-wide break-all select-all">{account.identifier}</span>
                        </p>
                        <p className="text-ink-2">
                            {account.holderName} · {account.holderDocument}
                        </p>
                        {account.instructions !== null ? <p className="text-ink-3 mt-0.5 text-xs">{account.instructions}</p> : null}
                    </div>
                    <CopyButton value={account.identifier} label={`${account.identifierLabel.toLowerCase()} de ${account.methodLabel}`} />
                </li>
            ))}
        </ul>
    );
}
