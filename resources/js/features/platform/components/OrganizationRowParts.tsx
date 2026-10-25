import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { type OrganizationRow } from '@/types/platform';
import { Link } from '@inertiajs/react';
import { OrganizationStatusBadge } from './labels';

export function RiskBadges({ row, dashWhenNone = false }: { row: OrganizationRow; dashWhenNone?: boolean }) {
    const none = !row.risks.atLimit && !row.risks.inactive && !row.risks.overdueInvoices && !row.hasActiveSupportAccess;

    if (none) {
        return dashWhenNone ? <span className="text-ink-3">—</span> : null;
    }

    return (
        <span className="flex flex-wrap gap-1">
            {row.risks.atLimit ? <Badge tone="warning">En el límite</Badge> : null}
            {row.risks.inactive ? <Badge tone="neutral">Inactiva 30 d</Badge> : null}
            {row.risks.overdueInvoices ? <Badge tone="error">Factura vencida</Badge> : null}
            {row.hasActiveSupportAccess ? <Badge tone="info">Soporte abierto</Badge> : null}
        </span>
    );
}

export function OrganizationCard({ row, lastActivity }: { row: OrganizationRow; lastActivity: string }) {
    return (
        <li>
            <Link
                href={`/plataforma/organizaciones/${row.slug}`}
                className="border-line bg-base-100 shadow-card flex flex-col gap-2.5 rounded-2xl border p-3.5"
            >
                <span className="flex items-center gap-3">
                    <Avatar name={row.name} size={36} />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-[15px] font-bold">{row.name}</span>
                        <span className="text-ink-3 block truncate text-xs">
                            {row.planCode ?? '—'} · {lastActivity}
                        </span>
                    </span>
                    <OrganizationStatusBadge status={row.status} />
                </span>
                <RiskBadges row={row} />
            </Link>
        </li>
    );
}
