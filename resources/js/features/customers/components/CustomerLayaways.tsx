import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CustomerLayawayRow } from '@/types/customers';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Los apartados del cliente en el perfil (`REQ-BOF-041` RB-3): los vivos y,
 * aparte, la historia, cada uno con su catálogo de origen. El negocio ve el
 * motivo íntegro de una cancelación y quién la hizo (RB-6).
 *
 * Las acciones de cobranza —registrar un pago, cancelar, entregar— están en
 * la vista del apartado, a un clic; aquí, las que son del cliente: copiar su
 * código y reenviárselo a su correo (`REQ-IDN-008` SP-5).
 */
export function CustomerLayaways({ layaways, empty }: { layaways: readonly CustomerLayawayRow[]; empty: string }) {
    if (layaways.length === 0) {
        return <p className="text-sm opacity-70">{empty}</p>;
    }

    return (
        <ul className="divide-base-200 divide-y">
            {layaways.map((layaway) => (
                <li key={layaway.code} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <span className="flex flex-wrap items-center gap-2">
                            <Link href={layaway.url} className="link font-mono text-sm">
                                {layaway.code}
                            </Link>
                            <Badge tone="neutral" outline>
                                {layaway.statusLabel}
                            </Badge>
                        </span>
                        <span>{layaway.productName}</span>
                        {layaway.catalogName !== null ? <span className="text-xs opacity-70">Del catálogo {layaway.catalogName}</span> : null}
                        {layaway.cancellationReason !== null ? (
                            <span className="text-xs">
                                Cancelado{layaway.cancelledBy !== null ? ` por ${layaway.cancelledBy}` : ''}: {layaway.cancellationReason}
                            </span>
                        ) : null}
                    </div>
                    <div className="flex flex-col items-start gap-1 text-sm sm:items-end">
                        <span>
                            Pagado <MoneyLabel money={layaway.paid} /> de <MoneyLabel money={layaway.total} />
                        </span>
                        {layaway.nextDueDate !== null ? (
                            <span className="text-xs opacity-70">Próximo vencimiento: {formatDay(layaway.nextDueDate)}</span>
                        ) : null}
                        {layaway.resendCodeUrl !== null ? <CodeActions code={layaway.code} resendUrl={layaway.resendCodeUrl} /> : null}
                    </div>
                </li>
            ))}
        </ul>
    );
}

/** SP-7 · sin correo, el código se copia para dictarlo. */
function CodeActions({ code, resendUrl }: { code: string; resendUrl: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <span className="flex gap-2">
            <button
                type="button"
                className="btn btn-ghost btn-xs"
                onClick={() => void navigator.clipboard?.writeText(code).then(() => setCopied(true))}
            >
                {copied ? 'Código copiado' : 'Copiar código'}
            </button>
            <button type="button" className="btn btn-ghost btn-xs" onClick={() => router.post(resendUrl, {}, { preserveScroll: true })}>
                Reenviar código
            </button>
        </span>
    );
}
