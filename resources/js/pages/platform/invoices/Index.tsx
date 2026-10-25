import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { ReceiptReview } from '@/features/platform/components/ReceiptReview';
import PlatformLayout from '@/layouts/platform-layout';
import { cn } from '@/lib/utils';
import { type PendingSubscriptionReceipt } from '@/types/platform';
import { cilExternalLink, cilImage } from '@coreui/icons';
import { useState } from 'react';

export default function InvoicesIndex({ receipts }: { receipts: readonly PendingSubscriptionReceipt[] }) {
    const [selectedId, setSelectedId] = useState<number | null>(receipts[0]?.id ?? null);
    const [failed, setFailed] = useState<ReadonlySet<number>>(new Set());
    const selected = receipts.find((receipt) => receipt.id === selectedId) ?? receipts[0] ?? null;

    return (
        <PlatformLayout title="Comprobantes de suscripción" description="Pagos que los negocios reportan por su plan. Los más antiguos primero.">
            {selected === null ? (
                <p className="border-line bg-base-100 text-ink-2 rounded-2xl border p-6 text-sm">No hay comprobantes por revisar.</p>
            ) : (
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)_minmax(0,360px)] lg:gap-5">
                    <ul
                        aria-label="Comprobantes por revisar"
                        className="border-line bg-base-100 shadow-card flex flex-col overflow-hidden rounded-2xl border"
                    >
                        {receipts.map((receipt) => {
                            const active = receipt.id === selected.id;

                            return (
                                <li key={receipt.id} className="border-line not-last:border-b">
                                    <button
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() => setSelectedId(receipt.id)}
                                        className={cn(
                                            'flex w-full cursor-pointer items-center gap-3 p-3.5 text-left',
                                            active ? 'bg-primary-soft' : 'hover:bg-surface-2',
                                        )}
                                    >
                                        <span className="bg-surface-2 text-ink-3 flex size-9 shrink-0 items-center justify-center rounded-lg">
                                            <Icon icon={cilImage} />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-semibold">{receipt.organization.name}</span>
                                            <span className="text-ink-3 block truncate text-xs">
                                                <span className="capitalize">{receipt.plan}</span> · {receipt.method}
                                            </span>
                                        </span>
                                        <span className="flex flex-col items-end gap-1">
                                            <span className="text-sm font-semibold whitespace-nowrap">{receipt.declared}</span>
                                            {receipt.isLate ? <Badge tone="warning">Atrasado</Badge> : null}
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>

                    <div className="bg-base-300/60 flex min-h-[320px] flex-col items-center justify-center gap-3 rounded-2xl p-5 lg:min-h-[440px]">
                        {failed.has(selected.id) ? (
                            <p className="text-ink-2 my-auto text-sm">Este comprobante no se puede previsualizar (puede ser un PDF).</p>
                        ) : (
                            <img
                                src={selected.fileUrl}
                                alt={`Comprobante de ${selected.organization.name}`}
                                className="shadow-pop max-h-[520px] max-w-full rounded-xl object-contain"
                                onError={() => setFailed((current) => new Set(current).add(selected.id))}
                            />
                        )}
                        <a href={selected.fileUrl} target="_blank" rel="noreferrer" className="btn btn-sm">
                            <Icon icon={cilExternalLink} size={14} />
                            Ver comprobante
                        </a>
                    </div>

                    <div className="border-line bg-base-100 shadow-card rounded-2xl border p-5">
                        <ReceiptReview key={selected.id} receipt={selected} />
                    </div>
                </div>
            )}
        </PlatformLayout>
    );
}
