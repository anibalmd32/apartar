import { BackLink } from '@/components/ui/back-link';
import { Badge } from '@/components/ui/badge';
import PlatformLayout from '@/layouts/platform-layout';
import { LAYAWAY_TONES, toneOf } from '@/lib/status-tones';
import { pf } from '@/lib/surfaces';

interface LayawayRow {
    code: string;
    customer: string;
    product: string;
    status: string;
    statusLabel: string;
    totalCents: number;
    paidCents: number;
    remainingCents: number | null;
    createdAt: string | null;
}

const usd = (cents: number) => `$${(cents / 100).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function SupportLayaways({
    organization,
    layaways,
}: {
    organization: { id: number; name: string; slug: string };
    layaways: readonly LayawayRow[];
}) {
    return (
        <PlatformLayout title={`Apartados de ${organization.name}`} description="Acceso de soporte · solo lectura. Este acceso queda registrado.">
            <BackLink href={pf(`/organizaciones/${organization.slug}`)}>{organization.name}</BackLink>
            <div className="table-frame table-stack md:overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th scope="col">Apartado</th>
                            <th scope="col">Estado</th>
                            <th scope="col" className="text-right">
                                Total
                            </th>
                            <th scope="col" className="text-right">
                                Pagado
                            </th>
                            <th scope="col" className="text-right">
                                Falta
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {layaways.map((row) => (
                            <tr key={row.code}>
                                <td data-cell="primary">
                                    <p className="font-mono text-[13px] font-semibold whitespace-nowrap">{row.code}</p>
                                    <p className="font-medium">{row.customer}</p>
                                    <p className="text-ink-3 text-xs">{row.product}</p>
                                </td>
                                <td data-cell="shrink" className="whitespace-nowrap">
                                    <Badge tone={toneOf(LAYAWAY_TONES, row.status)}>{row.statusLabel}</Badge>
                                </td>
                                <td data-label="Total" className="text-right whitespace-nowrap tabular-nums">
                                    {usd(row.totalCents)}
                                </td>
                                <td data-label="Pagado" className="text-right whitespace-nowrap tabular-nums">
                                    {row.paidCents === 0 ? null : usd(row.paidCents)}
                                </td>
                                <td data-label="Falta" className="text-right font-semibold whitespace-nowrap tabular-nums">
                                    {row.remainingCents === null ? (
                                        <span className="text-ink-3 font-normal">Cerrado</span>
                                    ) : row.remainingCents === 0 ? null : (
                                        usd(row.remainingCents)
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </PlatformLayout>
    );
}
