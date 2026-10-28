import { SectionCard } from '@/components/ui/section-card';
import { type MailSummary } from '@/types/mail';
import { Link } from '@inertiajs/react';
import { count, DASH, percent } from './format';

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div className="bg-surface-2 flex flex-col gap-0.5 rounded-xl px-3.5 py-3">
            <span className="text-ink-3 text-xs">{label}</span>
            <span className="text-lg font-extrabold">{value}</span>
        </div>
    );
}

export function MailSummaryCard({ summary }: { summary: MailSummary | null }) {
    return (
        <SectionCard
            title="Correo"
            subtitle="Últimos 30 días."
            aria-label="Correo"
            action={
                <Link href="/plataforma/correos" className="link-hover">
                    Ver métricas
                </Link>
            }
        >
            {summary === null ? (
                <p>{DASH}</p>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <Figure label="Enviados" value={count(summary.sent)} />
                    <Figure label="Entrega" value={percent(summary.deliveryRate)} />
                    <Figure label="Apertura" value={percent(summary.openRate)} />
                    <Figure label="Rebote" value={percent(summary.bounceRate)} />
                    <Link
                        href="/plataforma/correos/bandeja?filtro=no-leidos"
                        className="bg-surface-2 hover:bg-base-300 flex flex-col gap-0.5 rounded-xl px-3.5 py-3"
                    >
                        <span className="text-ink-3 text-xs">Sin leer</span>
                        <span className="text-lg font-extrabold">{count(summary.unreadThreads)}</span>
                    </Link>
                </div>
            )}
        </SectionCard>
    );
}
