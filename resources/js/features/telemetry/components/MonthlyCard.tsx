import { SectionCard } from '@/components/ui/section-card';
import { formatMonth } from '@/lib/dates';
import { type CatalogAnalytics } from '@/types/analytics';

const NUMBER = new Intl.NumberFormat('es-VE');

export function MonthlyCard({ monthly }: { monthly: NonNullable<CatalogAnalytics['monthly']> }) {
    const months = monthly.filter((row) => row.visits !== 0 || row.subscriptions !== 0).reverse();

    return (
        <SectionCard title="Últimos 12 meses" subtitle="El mes más reciente, primero" aria-label="Últimos 12 meses" flush={months.length > 0}>
            {months.length === 0 ? (
                <p className="text-ink-3 text-sm">Sin visitas ni suscriptores en los últimos 12 meses.</p>
            ) : (
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
                            {months.map((row) => (
                                <tr key={row.month}>
                                    <td className="whitespace-nowrap first-letter:uppercase">{formatMonth(row.month)}</td>
                                    <td className="text-right font-semibold">{NUMBER.format(row.visits)}</td>
                                    <td className="text-right">{row.subscriptions}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}
