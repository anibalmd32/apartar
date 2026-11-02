import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field-error';
import { Icon } from '@/components/ui/icon';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { formatAmountTyping, RATE_DECIMALS, typedAmount } from '@/lib/amount-typing';
import { formatDate } from '@/lib/dates';
import { pf } from '@/lib/surfaces';
import { type PendingSubscriptionReceipt } from '@/types/platform';
import { cilCheckAlt, cilX } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type ReactNode, useId, useState } from 'react';

const SETTLED_CURRENCIES = [
    { value: 'USD', label: 'USD' },
    { value: 'VES', label: 'VES' },
] as const;

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3 text-[13px]">
            <dt className="text-ink-3">{label}</dt>
            <dd className="text-right font-semibold">{children}</dd>
        </div>
    );
}

export function ReceiptReview({ receipt }: { receipt: PendingSubscriptionReceipt }) {
    const [mode, setMode] = useState<'approve' | 'reject'>('approve');
    const reasonId = useId();
    const approve = useForm({ settledAmount: formatAmountTyping(receipt.outstanding.usd), settledCurrency: 'USD', rate: '' });
    const reject = useForm({ reason: '' });

    return (
        <section aria-label={`Comprobante de ${receipt.organization.name}`} className="flex flex-col gap-4">
            <h2 className="text-lg font-bold">
                {receipt.organization.name} · <span className="capitalize">{receipt.plan}</span>
            </h2>

            <dl className="flex flex-col gap-2.5">
                <Row label="Falta por pagar">{receipt.outstanding.usd}</Row>
                <Row label="Declarado">
                    {receipt.declared} · {receipt.method}
                </Row>
                {receipt.reference || receipt.bank ? (
                    <Row label="Referencia">{[receipt.reference, receipt.bank].filter(Boolean).join(' · ')}</Row>
                ) : null}
                <Row label="Pagado el">{formatDate(receipt.paidAt)}</Row>
            </dl>

            {mode === 'approve' ? (
                <form
                    aria-label="Aprobar el comprobante"
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        approve.post(pf(`/comprobantes/${receipt.id}/aprobar`));
                    }}
                >
                    <TextField
                        label="Importe conciliado"
                        inputMode="decimal"
                        autoComplete="off"
                        className="tabular-nums"
                        value={approve.data.settledAmount}
                        onChange={(event) => approve.setData('settledAmount', typedAmount(event))}
                        error={approve.errors.settledAmount}
                    />
                    <div className="grid grid-cols-[112px_minmax(0,1fr)] items-start gap-2">
                        <SelectField
                            label="Moneda"
                            options={SETTLED_CURRENCIES}
                            value={approve.data.settledCurrency}
                            onChange={(event) => approve.setData('settledCurrency', event.target.value)}
                            error={approve.errors.settledCurrency}
                        />
                        <TextField
                            label="Tasa (opcional)"
                            inputMode="decimal"
                            autoComplete="off"
                            className="tabular-nums"
                            value={approve.data.rate}
                            onChange={(event) => approve.setData('rate', typedAmount(event, RATE_DECIMALS))}
                            error={approve.errors.rate}
                        />
                    </div>
                    <div className="mt-1 flex flex-col gap-2">
                        <Button type="submit" variant="success" block loading={approve.processing} className="h-12 text-[15px]">
                            {approve.processing ? null : <Icon icon={cilCheckAlt} />}
                            Aprobar pago
                        </Button>
                        <Button variant="secondary" block className="h-12 text-[15px]" onClick={() => setMode('reject')}>
                            <Icon icon={cilX} />
                            Rechazar
                        </Button>
                    </div>
                </form>
            ) : (
                <form
                    aria-label="Rechazar el comprobante"
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        reject.post(pf(`/comprobantes/${receipt.id}/rechazar`));
                    }}
                >
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={reasonId} className="text-[13px] font-semibold">
                            Motivo del rechazo (el negocio lo verá)
                        </label>
                        <textarea
                            id={reasonId}
                            className="textarea w-full"
                            value={reject.data.reason}
                            aria-invalid={reject.errors.reason ? true : undefined}
                            onChange={(event) => reject.setData('reason', event.target.value)}
                        />
                        {reject.errors.reason ? <FieldError>{reject.errors.reason}</FieldError> : null}
                    </div>
                    <div className="mt-1 flex flex-col gap-2">
                        <Button
                            type="submit"
                            variant="danger"
                            block
                            loading={reject.processing}
                            disabled={reject.data.reason.trim() === ''}
                            className="h-12 text-[15px]"
                        >
                            {reject.processing ? null : <Icon icon={cilX} />}
                            Rechazar comprobante
                        </Button>
                        <Button variant="ghost" block className="h-12 text-[15px]" onClick={() => setMode('approve')}>
                            Volver a aprobar
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
