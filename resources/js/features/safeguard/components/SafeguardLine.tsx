import { FileLink } from '@/components/ui/file-link';
import { Icon } from '@/components/ui/icon';
import { type SafeguardView } from '@/types/safeguard';
import { cilShieldAlt } from '@coreui/icons';

interface SafeguardLineProps {
    safeguard: SafeguardView;
    onEdit: () => void;
}

const RATE = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function worth(safeguard: SafeguardView): string {
    if (safeguard.equivalent === null) {
        return 'Sin tasa paralela de ese día: no se puede calcular cuánto vale en paralelo.';
    }

    const rate = safeguard.parallelRate !== null ? ` (paralelo ${RATE.format(Number(safeguard.parallelRate))})` : '';
    const gap = safeguard.gap !== null ? ` · diferencia ${safeguard.gap}` : '';

    return `Vale ${safeguard.equivalent} en paralelo${rate}${gap}`;
}

export function SafeguardLine({ safeguard, onEdit }: SafeguardLineProps) {
    const record = safeguard.record;

    return (
        <div className="bg-surface-2 flex flex-col gap-1 rounded-lg px-2.5 py-2 text-xs" aria-label="Resguardo del pago">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-ink-2 flex items-center gap-1.5 font-semibold">
                    <Icon icon={cilShieldAlt} size={14} />
                    {worth(safeguard)}
                </span>
                <button type="button" className="link link-primary hit-area" onClick={onEdit}>
                    {record === null ? 'Registrar resguardo' : 'Editar resguardo'}
                </button>
            </div>
            {record !== null ? (
                <p className="text-ink-2 flex flex-wrap items-center gap-x-2">
                    <span>Resguardado en {record.destinationLabel}</span>
                    {record.reference !== null ? <span>ref. {record.reference}</span> : null}
                    {record.proofUrl !== null ? (
                        <FileLink url={record.proofUrl} title="Comprobante del resguardo" className="link link-primary">
                            Ver comprobante del resguardo
                        </FileLink>
                    ) : null}
                    {record.note !== null ? <span className="text-ink-3">{record.note}</span> : null}
                </p>
            ) : (
                <p className="text-ink-3">Todavía no indicas dónde quedó este dinero.</p>
            )}
        </div>
    );
}
