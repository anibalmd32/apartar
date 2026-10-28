import { DateRangeField } from '@/components/ui/date-range-field';
import { BreakdownTable } from '@/features/platform/mail/BreakdownTable';
import { DailyChart } from '@/features/platform/mail/DailyChart';
import { BounceReasons, TopLinks } from '@/features/platform/mail/LinksAndBounces';
import { MailTabs } from '@/features/platform/mail/MailTabs';
import { MetricTiles } from '@/features/platform/mail/MetricTiles';
import PlatformLayout from '@/layouts/platform-layout';
import { cn } from '@/lib/utils';
import { type MailMetrics } from '@/types/mail';
import { Link, router } from '@inertiajs/react';

interface MetricsProps {
    metrics: MailMetrics;
    filters: { desde: string; hasta: string };
}

const QUICK_RANGES = [7, 30, 90] as const;

function localToday(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

function isoDaysAgo(to: string, days: number): string {
    const date = new Date(`${to}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() - (days - 1));

    return date.toISOString().slice(0, 10);
}

export default function MailMetricsPage({ metrics, filters }: MetricsProps) {
    const visit = (desde: string, hasta: string) =>
        router.get(
            '/plataforma/correos',
            { desde: desde === '' ? null : desde, hasta: hasta === '' ? null : hasta },
            { preserveState: true, replace: true },
        );

    return (
        <PlatformLayout title="Correos" description="Todo lo que sale y entra por apartar.app, medido con los avisos de Mailtrap que guardamos.">
            <MailTabs />

            <section aria-label="Fechas" className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <nav aria-label="Rango rápido" className="bg-surface-2 inline-flex items-center gap-0.5 self-start rounded-[10px] p-[3px]">
                    {QUICK_RANGES.map((days) => {
                        const today = localToday();
                        const active = filters.desde === isoDaysAgo(filters.hasta, days) && filters.hasta === today;

                        return (
                            <Link
                                key={days}
                                href={`/plataforma/correos?desde=${isoDaysAgo(today, days)}&hasta=${today}`}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'pointer-coarse:h-11 flex h-[34px] items-center rounded-lg px-3.5 text-[13px]',
                                    active ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
                                )}
                            >
                                {days} días
                            </Link>
                        );
                    })}
                </nav>
                <DateRangeField
                    label="Fechas"
                    fromLabel="Desde"
                    from={filters.desde}
                    to={filters.hasta}
                    fromName="desde"
                    toName="hasta"
                    onFromChange={(event) => visit(event.target.value, filters.hasta)}
                    onToChange={(event) => visit(filters.desde, event.target.value)}
                />
            </section>

            <MetricTiles metrics={metrics} />

            <DailyChart daily={metrics.daily} />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <BreakdownTable
                    title="Por tipo de correo"
                    heading="Tipo"
                    rows={metrics.byCategory.map((row) => ({ ...row, key: row.category }))}
                    empty="Ningún correo enviado en estas fechas."
                />
                <BreakdownTable
                    title="Por proveedor del destinatario"
                    heading="Proveedor"
                    subtitle="Gmail, Outlook y compañía; el resto, por su dominio."
                    rows={metrics.byProvider.map((row) => ({ ...row, key: row.provider, label: row.provider }))}
                    empty="Ningún correo enviado en estas fechas."
                />
            </div>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[3fr_2fr]">
                <TopLinks links={metrics.topLinks} />
                <BounceReasons reasons={metrics.bounceReasons} inbound={metrics.totals.inbound} />
            </div>
        </PlatformLayout>
    );
}
