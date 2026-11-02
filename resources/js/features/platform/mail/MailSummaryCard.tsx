import { FigureItem, FigureList } from '@/components/ui/figure-list';
import { SectionCard } from '@/components/ui/section-card';
import { pf } from '@/lib/surfaces';
import { type MailSummary } from '@/types/mail';
import { Link } from '@inertiajs/react';
import { count, DASH, percent } from './format';

export function MailSummaryCard({ summary }: { summary: MailSummary | null }) {
    return (
        <SectionCard
            title="Correo"
            subtitle="Últimos 30 días."
            aria-label="Correo"
            action={
                <Link href={pf('/correos')} className="link-hover">
                    Ver métricas
                </Link>
            }
        >
            {summary === null ? (
                <p>{DASH}</p>
            ) : (
                <FigureList className="lg:grid-cols-5">
                    <FigureItem label="Enviados" value={count(summary.sent)} />
                    <FigureItem label="Entrega" value={percent(summary.deliveryRate)} />
                    <FigureItem label="Apertura" value={percent(summary.openRate)} />
                    <FigureItem label="Rebote" value={percent(summary.bounceRate)} />
                    <FigureItem label="Sin leer" value={count(summary.unreadThreads)} href={pf('/correos/bandeja?filtro=no-leidos')} />
                </FigureList>
            )}
        </SectionCard>
    );
}
