import { Badge } from '@/components/ui/badge';
import { BankMark } from '@/components/ui/bank-mark';
import { FileLink } from '@/components/ui/file-link';
import { IconChip } from '@/components/ui/icon-chip';
import { CorrectPaymentSheet } from '@/features/receipts/components/CorrectPaymentSheet';
import { MovePaymentSheet } from '@/features/receipts/components/MovePaymentSheet';
import { type PaymentAction, PaymentRowActions } from '@/features/receipts/components/PaymentRowActions';
import { VoidPaymentSheet } from '@/features/receipts/components/VoidPaymentSheet';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { appliedTo } from '@/features/receipts/lib/payment-applied';
import { SafeguardLine } from '@/features/safeguard/components/SafeguardLine';
import { SafeguardSheet } from '@/features/safeguard/components/SafeguardSheet';
import { cn } from '@/lib/utils';
import { type PaymentFieldOptions } from '@/types/layaway-backoffice';
import { type PaymentRow, type PaymentVoid } from '@/types/receipt';
import { type SafeguardOptions } from '@/types/safeguard';
import { cilBan, cilCash } from '@coreui/icons';
import { useState } from 'react';

interface PaymentHistoryProps {
    payments: readonly PaymentRow[];
    empty: string;
    reopens?: boolean;
    correctionFields?: PaymentFieldOptions | null;
    safeguardOptions?: SafeguardOptions | null;
}

function detail(payment: PaymentRow): string {
    const segments = [
        payment.declaredAmount !== payment.amountUsd.usd || payment.rateApplied !== null ? payment.declaredAmount : null,
        payment.rateApplied !== null ? `tasa ${formatRate(payment.rateApplied)} (${payment.rateSourceLabel}), la del día del pago` : null,
        appliedTo(payment),
    ].filter((segment): segment is string => segment !== null);
    const text = segments.join(' · ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

function voidLine(voided: PaymentVoid): string {
    const who = voided.by !== null ? ` por ${voided.by}` : '';
    const why = voided.reason !== null ? `. Motivo: ${voided.reason}` : '';

    return `Anulado el ${voided.at}${who}${why}`;
}

function PaymentItem({
    payment,
    onAction,
    canCorrect,
    onSafeguard,
}: {
    payment: PaymentRow;
    onAction: (payment: PaymentRow, action: PaymentAction) => void;
    canCorrect: boolean;
    onSafeguard: ((payment: PaymentRow) => void) | null;
}) {
    const voided = payment.voided ?? null;

    return (
        <li className="flex items-start gap-3 py-3 text-[13px] first:pt-0 last:pb-0" data-voided={voided !== null || undefined}>
            <IconChip icon={voided !== null ? cilBan : cilCash} tone={voided !== null ? 'neutral' : 'success'} />
            <div className={cn('flex min-w-0 flex-1 flex-col gap-1', voided !== null && 'opacity-70')}>
                <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                    <span className="flex items-center gap-2">
                        <span className={cn('text-sm font-bold', voided !== null && 'text-ink-3 line-through')}>{payment.amountUsd.usd}</span>
                        {voided !== null ? <Badge tone="error">Anulado</Badge> : null}
                    </span>
                    <span className="text-ink-3 text-xs">{payment.settledAt}</span>
                </div>
                {detail(payment) !== '' ? <p className={cn('text-ink-2', voided !== null && 'line-through')}>{detail(payment)}</p> : null}
                <div className="text-ink-3 flex flex-wrap items-center gap-2 text-xs">
                    <span>{payment.method}</span>
                    {payment.bankReference !== null ? (
                        <span className="inline-flex items-center gap-1.5">
                            <BankMark bank={payment.bank} logoUrl={payment.bankLogoUrl} />
                            {payment.bankReference}
                        </span>
                    ) : null}
                    {payment.attachmentUrl !== null ? (
                        <FileLink url={payment.attachmentUrl} title="Comprobante del pago" className="link link-primary">
                            Ver comprobante
                        </FileLink>
                    ) : null}
                    {payment.registeredByBusiness ? (
                        <Badge tone="info" outline>
                            Registrado por el negocio
                        </Badge>
                    ) : null}
                </div>
                {payment.note !== null ? <p className="text-ink-2 text-xs">{payment.note}</p> : null}
                {voided !== null ? <p className="text-error text-xs">{voidLine(voided)}</p> : null}
                {payment.safeguard && onSafeguard !== null ? (
                    <SafeguardLine safeguard={payment.safeguard} onEdit={() => onSafeguard(payment)} />
                ) : null}
            </div>
            {payment.voidUrl ? <PaymentRowActions payment={payment} onAction={onAction} canCorrect={canCorrect} /> : null}
        </li>
    );
}

export function PaymentHistory({ payments, empty, reopens = false, correctionFields = null, safeguardOptions = null }: PaymentHistoryProps) {
    const [selected, setSelected] = useState<{ payment: PaymentRow; action: PaymentAction } | null>(null);
    const [guarding, setGuarding] = useState<PaymentRow | null>(null);
    const chosen = (action: PaymentAction): PaymentRow | null => (selected?.action === action ? selected.payment : null);
    const close = () => setSelected(null);

    if (payments.length === 0) {
        return <p className="text-ink-2 text-sm">{empty}</p>;
    }

    return (
        <>
            <ul className="divide-line flex flex-col divide-y" aria-label="Pagos">
                {payments.map((payment, index) => (
                    <PaymentItem
                        key={`${payment.settledAt}-${index}`}
                        payment={payment}
                        onAction={(row, action) => setSelected({ payment: row, action })}
                        canCorrect={correctionFields !== null}
                        onSafeguard={safeguardOptions !== null ? setGuarding : null}
                    />
                ))}
            </ul>
            <VoidPaymentSheet payment={chosen('void')} reopens={reopens} onClose={close} />
            {correctionFields !== null ? <CorrectPaymentSheet payment={chosen('edit')} fields={correctionFields} onClose={close} /> : null}
            <MovePaymentSheet payment={chosen('move')} onClose={close} />
            {guarding?.safeguard && safeguardOptions !== null ? (
                <SafeguardSheet
                    key={guarding.safeguard.storeUrl}
                    safeguard={guarding.safeguard}
                    options={safeguardOptions}
                    amount={guarding.amountUsd.usd}
                    onClose={() => setGuarding(null)}
                />
            ) : null}
        </>
    );
}
