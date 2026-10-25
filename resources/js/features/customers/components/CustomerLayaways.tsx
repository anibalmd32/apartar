import { Badge } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CustomerLayawayRow } from '@/types/customers';
import { cilBasket } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

export function CustomerLayaways({ layaways, empty }: { layaways: readonly CustomerLayawayRow[]; empty: string }) {
    if (layaways.length === 0) {
        return <p className="text-ink-2 text-sm">{empty}</p>;
    }

    return (
        <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {layaways.map((layaway) => {
                const total = Number(layaway.total.cents);
                const percent = total > 0 ? Math.min(100, (Number(layaway.paid.cents) / total) * 100) : 0;

                return (
                    <li key={layaway.code} className="bg-base-100 border-line shadow-card flex flex-col gap-3 rounded-2xl border p-4">
                        <div className="flex items-start gap-3">
                            <IconChip icon={cilBasket} tone="neutral" size={40} />
                            <div className="flex min-w-0 flex-1 flex-col">
                                <span className="truncate text-sm font-bold">{layaway.productName}</span>
                                <Link href={layaway.url} className="text-primary-ink self-start text-xs font-semibold hover:underline">
                                    {layaway.code}
                                </Link>
                            </div>
                            <Badge tone="neutral">{layaway.statusLabel}</Badge>
                        </div>

                        <span className="bg-base-300 block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                            <span className="bg-success block h-full rounded-full" style={{ width: `${percent}%` }} />
                        </span>

                        <div className="text-ink-3 flex flex-wrap justify-between gap-2 text-xs">
                            <span>
                                Pagado <MoneyLabel money={layaway.paid} /> de <MoneyLabel money={layaway.total} />
                            </span>
                            <span className="text-base-content font-bold">
                                Resta <MoneyLabel money={layaway.remaining} />
                            </span>
                        </div>

                        {layaway.catalogName !== null ? <span className="text-ink-3 text-xs">Del catálogo {layaway.catalogName}</span> : null}
                        {layaway.nextDueDate !== null ? (
                            <span className="text-ink-2 text-xs">Próximo vencimiento: {formatDay(layaway.nextDueDate)}</span>
                        ) : null}
                        {layaway.cancellationReason !== null ? (
                            <span className="text-ink-2 text-xs">
                                Cancelado{layaway.cancelledBy !== null ? ` por ${layaway.cancelledBy}` : ''}: {layaway.cancellationReason}
                            </span>
                        ) : null}

                        <div className="mt-auto flex flex-wrap items-center justify-end gap-2">
                            {layaway.resendCodeUrl !== null ? <CodeActions code={layaway.code} resendUrl={layaway.resendCodeUrl} /> : null}
                            <Link href={layaway.url} className="btn btn-secondary btn-sm">
                                Ver
                            </Link>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function CodeActions({ code, resendUrl }: { code: string; resendUrl: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <span className="flex gap-2">
            <button
                type="button"
                className="btn btn-ghost btn-sm"
                onClick={() => void navigator.clipboard?.writeText(code).then(() => setCopied(true))}
            >
                {copied ? 'Código copiado' : 'Copiar código'}
            </button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => router.post(resendUrl, {}, { preserveScroll: true })}>
                Reenviar código
            </button>
        </span>
    );
}
