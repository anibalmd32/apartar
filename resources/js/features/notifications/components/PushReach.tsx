import { Button } from '@/components/ui/button';
import { IconChip } from '@/components/ui/icon-chip';
import { cilPeople } from '@coreui/icons';

export interface PushReport {
    readonly recipients: number;
    readonly reachable: number;
    readonly unreachable: number;
    readonly queued: number;
}

interface PushReachProps {
    report: PushReport;
    busy: boolean;
    onConfirm: () => void;
    onChange: () => void;
}

export function PushReach({ report, busy, onConfirm, onChange }: PushReachProps) {
    return (
        <div className="bg-info-soft flex flex-col gap-3 rounded-xl p-3.5 sm:flex-row sm:items-center" role="status">
            <div className="flex min-w-0 flex-1 items-center gap-3">
                <IconChip icon={cilPeople} tone="info" size={36} />
                <div className="min-w-0">
                    <p className="text-sm font-bold">
                        {report.recipients} {report.recipients === 1 ? 'cliente' : 'clientes'} en ese grupo
                    </p>
                    <p className="text-ink-2 text-xs">
                        {report.reachable} con los avisos activados
                        {report.unreachable > 0 ? ` y ${report.unreachable} a los que no llegará` : ''}.
                    </p>
                </div>
            </div>
            <div className="flex shrink-0 gap-2">
                <Button size="sm" variant="ghost" onClick={onChange}>
                    Cambiar
                </Button>
                <Button size="sm" disabled={report.reachable === 0} loading={busy} onClick={onConfirm}>
                    Confirmar y enviar
                </Button>
            </div>
        </div>
    );
}
