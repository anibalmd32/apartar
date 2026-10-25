import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { formatAge } from '@/features/receipts/lib/inbox-format';
import { type DashboardPendingReceipt } from '@/types/dashboard';
import { cilDescription, cilImage } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export function PendingReceiptsCard({ receipts, inboxUrl }: { receipts: readonly DashboardPendingReceipt[] | null; inboxUrl: string }) {
    if (receipts === null || receipts.length === 0) {
        return null;
    }

    const now = Date.now();

    return (
        <SectionCard
            title="Comprobantes por revisar"
            subtitle="Los más antiguos primero"
            action={
                <Link href={inboxUrl} className="hover:underline">
                    Ver todos
                </Link>
            }
        >
            <ul className="divide-line -mt-1 flex flex-col divide-y">
                {receipts.map((receipt) => (
                    <li key={receipt.id}>
                        <Link
                            href={receipt.reviewUrl}
                            className="hover:bg-surface-2 -mx-2 flex items-center gap-3 rounded-xl px-2 py-3 transition-colors"
                        >
                            <span
                                aria-hidden="true"
                                className="bg-base-300 text-ink-3 flex h-11 w-10 shrink-0 items-center justify-center rounded-[7px]"
                            >
                                <Icon icon={receipt.file.kind === 'pdf' ? cilDescription : cilImage} size={16} />
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    {receipt.customerName ?? 'Cliente sin ficha'}
                                    {receipt.overdue ? <Badge tone="error">Atrasado</Badge> : null}
                                </span>
                                <span className="text-ink-3 truncate text-xs">
                                    {receipt.layawayCode ?? '—'} · {receipt.methodLabel}
                                </span>
                            </span>
                            <span className="flex flex-col items-end gap-0.5 text-right">
                                <span className="text-sm font-bold whitespace-nowrap">{receipt.declared}</span>
                                <span className={receipt.overdue ? 'text-error text-xs' : 'text-ink-3 text-xs'}>
                                    {receipt.submittedAt !== null ? formatAge(receipt.submittedAt, now) : ''}
                                </span>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}
