import { Badge } from '@/components/ui/badge';
import { SectionCard } from '@/components/ui/section-card';
import { formatMoment } from '@/features/exports/lib/export-format';

export interface PushHistoryEntry {
    readonly id: number;
    readonly title: string;
    readonly mode: 'segment' | 'selection';
    readonly recipients: number;
    readonly queued: number;
    readonly sender: string | null;
    readonly sentAt: string;
}

const MODES = { segment: 'Segmento', selection: 'Selección manual' } as const;

export function PushHistory({ history }: { history: readonly PushHistoryEntry[] }) {
    return (
        <SectionCard title="Historial de envíos">
            {history.length === 0 ? (
                <p className="text-ink-3 text-[13px]">Todavía no has enviado ningún aviso.</p>
            ) : (
                <ul className="divide-line flex flex-col divide-y">
                    {history.map((entry) => (
                        <li key={entry.id} className="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div className="flex min-w-0 flex-col">
                                <span className="truncate text-sm font-semibold">{entry.title}</span>
                                <span className="text-ink-3 text-xs">
                                    {MODES[entry.mode]} · {entry.queued} de {entry.recipients} · {formatMoment(entry.sentAt)}
                                    {entry.sender !== null ? ` · ${entry.sender}` : ''}
                                </span>
                            </div>
                            <Badge tone="success">Enviado</Badge>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
