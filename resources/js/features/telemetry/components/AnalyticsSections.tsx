import { Badge } from '@/components/ui/badge';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { type CatalogAnalytics } from '@/types/analytics';

type Analytics = CatalogAnalytics;

const FUNNEL_LABELS: Record<string, string> = {
    catalog_viewed: 'Vieron un catálogo',
    product_viewed: 'Abrieron un producto',
    layaway_form_started: 'Empezaron a apartar',
    layaway_created: 'Apartaron',
};

const NUMBER = new Intl.NumberFormat('es-VE');

function share(value: number, top: number): number {
    return top > 0 ? (value / top) * 100 : 0;
}

function percent(value: number, base: number): string {
    const ratio = base > 0 ? (value / base) * 100 : 0;

    return `${new Intl.NumberFormat('es-VE', { maximumFractionDigits: ratio < 10 ? 1 : 0 }).format(ratio)} %`;
}

export function FunnelCard({ funnel }: { funnel: NonNullable<Analytics['funnel']> }) {
    const top = funnel[0]?.count ?? 0;

    return (
        <SectionCard title="Embudo de conversión" subtitle="De la visita al apartado" aria-label="Embudo">
            {funnel.length === 0 ? (
                <p className="text-ink-3 text-sm">Sin datos.</p>
            ) : (
                <ol className="flex flex-col gap-4">
                    {funnel.map((step, index) => {
                        const previous = index > 0 ? funnel[index - 1] : undefined;

                        return (
                            <li key={step.event} className="flex flex-col gap-1.5">
                                {previous !== undefined ? (
                                    <p className="text-ink-3 -mt-2 text-[11px]" data-step-conversion>
                                        ↓ {percent(step.count, previous.count)} del paso anterior
                                    </p>
                                ) : null}
                                <Meter
                                    label={FUNNEL_LABELS[step.event] ?? step.event}
                                    value={
                                        <>
                                            {NUMBER.format(step.count)}{' '}
                                            <span className="text-ink-3 ms-1 text-xs font-normal">{percent(step.count, top)}</span>
                                        </>
                                    }
                                    percentage={share(step.count, top)}
                                />
                            </li>
                        );
                    })}
                </ol>
            )}
        </SectionCard>
    );
}

export function SearchesCard({ searches }: { searches: NonNullable<Analytics['searchesWithoutResults']> }) {
    return (
        <SectionCard title="Búsquedas sin resultados" subtitle="Ideas de productos que tus clientes quieren" aria-label="Búsquedas sin resultados">
            {searches.length === 0 ? (
                <p className="text-ink-3 text-sm">Todas las búsquedas encontraron algo.</p>
            ) : (
                <ol className="flex flex-wrap gap-2">
                    {searches.map((row, index) => (
                        <li
                            key={`${row.term}:${index}`}
                            className="border-line-strong text-ink-2 inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold"
                        >
                            {row.term}
                            <span className="text-ink-3 text-xs">{row.count}</span>
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}

export function TopProductsCard({ products }: { products: NonNullable<Analytics['topProducts']> }) {
    return (
        <SectionCard title="Productos más vistos" aria-label="Productos más vistos">
            {products.length === 0 ? (
                <p className="text-ink-3 text-sm">Sin productos vistos en este período.</p>
            ) : (
                <ol className="flex flex-col gap-3">
                    {products.map((row, index) => (
                        <li key={`${row.name}:${index}`} className="flex items-center justify-between gap-4 text-sm">
                            <span className="truncate font-semibold">{row.name}</span>
                            <Badge tone="primary" count>
                                {NUMBER.format(row.views)} {row.views === 1 ? 'vista' : 'vistas'}
                            </Badge>
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}

export function CatalogsCard({ catalogs }: { catalogs: NonNullable<Analytics['catalogs']> }) {
    const top = Math.max(0, ...catalogs.map((row) => row.visits));

    return (
        <SectionCard title="Por catálogo" subtitle="Visitas y apartados de cada uno" aria-label="Por catálogo">
            {catalogs.length === 0 ? (
                <p className="text-ink-3 text-sm">Sin visitas en este período.</p>
            ) : (
                <ol className="flex flex-col gap-4">
                    {catalogs.map((row) => (
                        <Meter
                            key={row.slug}
                            as="li"
                            label={`${row.name} · ${row.layaways} ${row.layaways === 1 ? 'apartado' : 'apartados'}`}
                            value={`${NUMBER.format(row.visits)} visitas`}
                            percentage={share(row.visits, top)}
                        />
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}

const MONTH = new Intl.DateTimeFormat('es', { month: 'short', year: 'numeric', timeZone: 'UTC' });

export function MonthlyCard({ monthly }: { monthly: NonNullable<Analytics['monthly']> }) {
    return (
        <SectionCard title="Últimos 12 meses" aria-label="Últimos 12 meses" flush>
            <div className="overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th className="text-right">Visitas</th>
                            <th className="text-right">Suscriptores (neto)</th>
                        </tr>
                    </thead>
                    <tbody>
                        {monthly.map((row) => (
                            <tr key={row.month}>
                                <td className="first-letter:uppercase">{MONTH.format(new Date(`${row.month}-01T00:00:00Z`))}</td>
                                <td className="text-right font-semibold">{NUMBER.format(row.visits)}</td>
                                <td className="text-right">{row.subscriptions}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </SectionCard>
    );
}
