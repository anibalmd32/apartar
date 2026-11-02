import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CompactList, CompactRow } from '@/components/ui/compact-list';
import { Icon } from '@/components/ui/icon';
import { formatBytes } from '@/features/exports/lib/export-format';
import { formatRelative } from '@/lib/dates';
import { type ExportRow, type ExportStatus, type ExportType } from '@/types/exports';
import { cilCloudDownload, cilReload } from '@coreui/icons';

const TONES: Record<ExportStatus, BadgeTone> = {
    queued: 'neutral',
    processing: 'info',
    completed: 'success',
    failed: 'error',
    expired: 'neutral',
};

type Retry = (type: ExportType, filters: Readonly<Record<string, string>>) => void;

interface ExportListProps {
    exports: readonly ExportRow[];
    canExport: boolean;
    onRetry: Retry;
}

function requested(row: ExportRow): string {
    return row.waitingMinutes !== null ? `hace ${row.waitingMinutes} min` : formatRelative(row.requestedIso);
}

function Availability({ row }: { row: ExportRow }) {
    if (row.status === 'expired') {
        return <>Se borró a los siete días</>;
    }

    if (row.status === 'failed') {
        return <span className="text-error">{row.errorMessage ?? 'No se pudo generar.'}</span>;
    }

    if (row.status === 'completed' && row.expiresIso !== null) {
        return <>hasta {formatRelative(row.expiresIso, { inSentence: true })}</>;
    }

    return <>—</>;
}

function ExportCards({ exports, canExport, onRetry }: ExportListProps) {
    return (
        <CompactList aria-label="Archivos" className="md:hidden">
            {exports.map((row) => (
                <CompactRow
                    key={row.id}
                    title={row.typeLabel}
                    href={row.downloadUrl}
                    external
                    activateLabel={row.downloadUrl !== null ? `Descargar ${row.typeLabel}` : undefined}
                    subtitle={row.status === 'failed' ? <Availability row={row} /> : `Pedida ${requested(row)}`}
                    status={<Badge tone={TONES[row.status]}>{row.statusLabel}</Badge>}
                    meta={
                        <>
                            {row.rowCount !== null ? <span>{row.rowCount === 1 ? '1 fila' : `${row.rowCount} filas`}</span> : null}
                            {row.fileSize !== null ? <span>{formatBytes(row.fileSize)}</span> : null}
                            {row.status === 'completed' || row.status === 'expired' ? (
                                <span className="text-ink-3">
                                    <Availability row={row} />
                                </span>
                            ) : null}
                        </>
                    }
                    action={
                        row.downloadUrl !== null ? (
                            <span className="text-primary-ink pointer-events-none flex size-11 items-center justify-center" aria-hidden="true">
                                <Icon icon={cilCloudDownload} size={20} />
                            </span>
                        ) : row.retryFilters !== null ? (
                            <button
                                type="button"
                                className="btn btn-square size-11 min-h-11"
                                disabled={!canExport}
                                aria-label={`Reintentar ${row.typeLabel}`}
                                onClick={() => onRetry(row.type, row.retryFilters ?? {})}
                            >
                                <Icon icon={cilReload} size={18} />
                            </button>
                        ) : null
                    }
                />
            ))}
        </CompactList>
    );
}

function ExportTable({ exports, canExport, onRetry }: ExportListProps) {
    return (
        <div className="bg-base-100 border-line shadow-card hidden overflow-x-auto rounded-2xl border md:block">
            <table className="table" aria-label="Archivos">
                <thead>
                    <tr>
                        <th scope="col">Archivo</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Solicitado</th>
                        <th scope="col">Filas</th>
                        <th scope="col">Tamaño</th>
                        <th scope="col">Disponible</th>
                        <th scope="col">
                            <span className="sr-only">Acciones</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {exports.map((row) => (
                        <tr key={row.id}>
                            <td className="font-semibold">{row.typeLabel}</td>
                            <td>
                                <Badge tone={TONES[row.status]}>{row.statusLabel}</Badge>
                            </td>
                            <td className="text-ink-2 whitespace-nowrap">{requested(row)}</td>
                            <td className="text-ink-2">{row.rowCount ?? '—'}</td>
                            <td className="text-ink-2 whitespace-nowrap">{row.fileSize !== null ? formatBytes(row.fileSize) : '—'}</td>
                            <td className="text-ink-3 text-xs">
                                <Availability row={row} />
                            </td>
                            <td className="text-right">
                                {row.downloadUrl !== null ? (
                                    <a className="btn btn-secondary btn-sm" href={row.downloadUrl}>
                                        <Icon icon={cilCloudDownload} size={14} />
                                        Descargar
                                    </a>
                                ) : null}
                                {row.retryFilters !== null ? (
                                    <Button
                                        size="sm"
                                        variant="secondary"
                                        disabled={!canExport}
                                        onClick={() => onRetry(row.type, row.retryFilters ?? {})}
                                    >
                                        <Icon icon={cilReload} size={14} />
                                        Reintentar
                                    </Button>
                                ) : null}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function ExportList(props: ExportListProps) {
    return (
        <>
            <ExportCards {...props} />
            <ExportTable {...props} />
        </>
    );
}
