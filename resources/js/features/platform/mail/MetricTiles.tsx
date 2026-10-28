import { StatCard } from '@/components/ui/stat-card';
import { type MailMetrics } from '@/types/mail';
import { cilCheckCircle, cilEnvelopeOpen, cilPaperPlane, cilWarning } from '@coreui/icons';
import { type ReactNode } from 'react';
import { count, minutes, percent } from './format';

function Tile({ label, value, children }: { label: string; value: ReactNode; children?: ReactNode }) {
    return (
        <div className="bg-surface-2 flex flex-col gap-0.5 rounded-xl px-3.5 py-3">
            <span className="text-ink-3 text-xs">{label}</span>
            <span className="text-lg font-extrabold">{value}</span>
            {children ? <span className="text-ink-3 text-xs">{children}</span> : null}
        </div>
    );
}

export function MetricTiles({ metrics }: { metrics: MailMetrics }) {
    const { totals, rates } = metrics;

    return (
        <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
                <section aria-label="Enviados" className="flex">
                    <StatCard label="Enviados" value={count(totals.sent)} icon={cilPaperPlane} href="/plataforma/correos/enviados" className="flex-1">
                        {count(totals.manual)} escritos por el equipo{totals.pending > 0 ? ` · ${count(totals.pending)} en cola` : ''}
                    </StatCard>
                </section>
                <section aria-label="Entrega" className="flex">
                    <StatCard label="Tasa de entrega" value={percent(rates.delivery)} icon={cilCheckCircle} tone="success" className="flex-1">
                        {count(totals.delivered)} entregados
                    </StatCard>
                </section>
                <section aria-label="Apertura" className="flex">
                    <StatCard label="Tasa de apertura" value={percent(rates.open)} icon={cilEnvelopeOpen} tone="info" className="flex-1">
                        {count(totals.openedUnique)} únicos · {count(totals.openedTotal)} en total
                    </StatCard>
                </section>
                <section aria-label="Rebotes" className="flex">
                    <StatCard label="Tasa de rebote" value={percent(rates.bounce)} icon={cilWarning} tone="warning" className="flex-1">
                        {count(totals.bounced)} definitivos · {count(totals.softBounced)} temporales
                    </StatCard>
                </section>
            </div>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <Tile label="Clics" value={percent(rates.click)}>
                    {count(totals.clickedUnique)} únicos · {count(totals.clickedTotal)} en total
                </Tile>
                <Tile label="Clic por apertura" value={percent(rates.clickToOpen)} />
                <Tile label="Spam" value={percent(rates.spam)}>
                    {count(totals.spam)} quejas
                </Tile>
                <Tile label="Bajas" value={percent(rates.unsubscribe)}>
                    {count(totals.unsubscribed)} bajas
                </Tile>
                <Tile label="Fallidos o rechazados" value={percent(rates.failure)}>
                    {count(totals.failed)} fallidos · {count(totals.rejected)} rechazados
                </Tile>
                <Tile label="Hasta la primera apertura" value={minutes(metrics.avgMinutesToOpen)}>
                    en promedio
                </Tile>
            </div>
        </>
    );
}
