import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type PendingSubscriptionReceipt } from '@/types/platform';
import { cilCheckAlt, cilX } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

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
    const approve = useForm({ settledAmount: receipt.outstanding.usd.replace(/[^\d.]/g, ''), settledCurrency: 'USD', rate: '' });
    const reject = useForm({ reason: '' });

    const tab = (value: typeof mode, label: string) => (
        <button
            type="button"
            aria-pressed={mode === value}
            onClick={() => setMode(value)}
            className={cn(
                'pointer-coarse:h-11 h-[34px] flex-1 cursor-pointer rounded-lg text-[13px]',
                mode === value ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 font-medium',
            )}
        >
            {label}
        </button>
    );

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
                <Row label="Pagado el">{receipt.paidAt}</Row>
            </dl>

            <div className="bg-surface-2 flex gap-0.5 rounded-[10px] p-[3px]">
                {tab('approve', 'Aprobar')}
                {tab('reject', 'Rechazar')}
            </div>

            {mode === 'approve' ? (
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        approve.post(`/plataforma/comprobantes/${receipt.id}/aprobar`);
                    }}
                >
                    <div className="grid grid-cols-[1fr_88px_1fr] gap-2">
                        <input
                            className="input w-full"
                            aria-label="Importe conciliado"
                            inputMode="decimal"
                            value={approve.data.settledAmount}
                            onChange={(event) => approve.setData('settledAmount', event.target.value)}
                        />
                        <select
                            className="select w-full"
                            aria-label="Moneda"
                            value={approve.data.settledCurrency}
                            onChange={(event) => approve.setData('settledCurrency', event.target.value)}
                        >
                            <option value="USD">USD</option>
                            <option value="VES">VES</option>
                        </select>
                        <input
                            className="input w-full"
                            aria-label="Tasa (opcional)"
                            placeholder="Tasa"
                            inputMode="decimal"
                            value={approve.data.rate}
                            onChange={(event) => approve.setData('rate', event.target.value)}
                        />
                    </div>
                    {Object.values(approve.errors).map((error) => (
                        <p key={error} className="text-error text-xs">
                            {error}
                        </p>
                    ))}
                    <Button type="submit" variant="success" block loading={approve.processing} className="h-12 text-[15px]">
                        {approve.processing ? null : <Icon icon={cilCheckAlt} />}
                        Aprobar pago
                    </Button>
                </form>
            ) : (
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        reject.post(`/plataforma/comprobantes/${receipt.id}/rechazar`);
                    }}
                >
                    <label className="flex flex-col gap-1.5">
                        <span className="text-[13px] font-semibold">Motivo del rechazo (el negocio lo verá)</span>
                        <textarea
                            className="textarea w-full"
                            value={reject.data.reason}
                            onChange={(event) => reject.setData('reason', event.target.value)}
                        />
                    </label>
                    {reject.errors.reason ? <p className="text-error text-xs">{reject.errors.reason}</p> : null}
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
                </form>
            )}
        </section>
    );
}
