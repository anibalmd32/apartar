import { Badge } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { type ReactNode } from 'react';

interface HealthCardProps {
    title: string;
    icon: string[];
    ok: boolean | null;
    problem?: string;
    children: ReactNode;
}

export function HealthCard({ title, icon, ok, problem = 'Revisar', children }: HealthCardProps) {
    const tone = ok === null ? 'neutral' : ok ? 'success' : 'warning';

    return (
        <section aria-label={title} className="border-line bg-base-100 shadow-card flex flex-col gap-3.5 rounded-2xl border p-5">
            <div className="flex items-center gap-2.5">
                <IconChip icon={icon} tone={tone} size={36} />
                <h2 className="flex-1 text-base font-bold">{title}</h2>
                <Badge tone={tone}>{ok === null ? 'Sin datos' : ok ? 'OK' : problem}</Badge>
            </div>
            <dl className="flex flex-col gap-2.5 text-[13px]">{children}</dl>
        </section>
    );
}

export function HealthRow({ label, children, tone }: { label: string; children: ReactNode; tone?: 'error' | 'warning' }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="text-ink-3">{label}</dt>
            <dd className={tone === 'error' ? 'text-error font-semibold' : tone === 'warning' ? 'text-warning font-semibold' : 'font-semibold'}>
                {children}
            </dd>
        </div>
    );
}
