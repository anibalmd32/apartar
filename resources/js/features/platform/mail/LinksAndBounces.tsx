import { Badge } from '@/components/ui/badge';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { type MailMetrics } from '@/types/mail';
import { count } from './format';

export function TopLinks({ links }: { links: MailMetrics['topLinks'] }) {
    const max = Math.max(1, ...links.map((link) => link.clicks));

    return (
        <SectionCard
            title="Enlaces más pulsados"
            subtitle="Solo si el seguimiento de clics está activo en Mailtrap: el plan gratuito no lo incluye."
            aria-label="Enlaces más pulsados"
        >
            {links.length === 0 ? (
                <p className="text-ink-3 text-sm">Ningún clic registrado en estas fechas.</p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {links.map((link) => (
                        <Meter
                            key={link.url}
                            as="li"
                            label={
                                <span className="block max-w-[46ch] truncate normal-case" title={link.url}>
                                    {link.url}
                                </span>
                            }
                            value={`${count(link.clicks)} · ${count(link.mails)} correos`}
                            percentage={(link.clicks / max) * 100}
                        />
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

export function BounceReasons({ reasons, inbound }: { reasons: MailMetrics['bounceReasons']; inbound: number }) {
    return (
        <SectionCard title="Rebotes y bandeja" aria-label="Rebotes y bandeja">
            {reasons.length === 0 ? (
                <p className="text-ink-3 text-sm">Sin rebotes en estas fechas.</p>
            ) : (
                <ul className="divide-line flex flex-col divide-y text-sm">
                    {reasons.map((reason) => (
                        <li key={`${reason.reason}-${reason.hard}`} className="flex items-center justify-between gap-3 py-2">
                            <span className="flex min-w-0 items-center gap-2">
                                <Badge tone={reason.hard ? 'error' : 'warning'}>{reason.hard ? 'Definitivo' : 'Temporal'}</Badge>
                                <span className="truncate">{reason.reason}</span>
                            </span>
                            <span className="font-semibold tabular-nums">{count(reason.count)}</span>
                        </li>
                    ))}
                </ul>
            )}
            <p className="border-line text-ink-2 border-t pt-3 text-sm">
                Correos recibidos en estas fechas: <strong>{count(inbound)}</strong>
            </p>
        </SectionCard>
    );
}
