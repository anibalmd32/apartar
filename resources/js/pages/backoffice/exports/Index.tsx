import { Alert } from '@/components/ui/alert';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { formatBytes, formatMoment } from '@/features/exports/lib/export-format';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { EXPORTS_URL, type ExportRow, type ExportsPageProps, type ExportStatus, type ExportType } from '@/types/exports';
import { cilBasket, cilCloudDownload, cilPeople, cilReload, cilWallet } from '@coreui/icons';
import { router } from '@inertiajs/react';

const TONES: Record<ExportStatus, BadgeTone> = {
    queued: 'neutral',
    processing: 'info',
    completed: 'success',
    failed: 'error',
    expired: 'neutral',
};

const SOURCES: Record<ExportType, { hint: string; icon: string[] }> = {
    collections: { hint: 'Cuotas por cobrar con el filtro que elijas', icon: cilWallet },
    customers: { hint: 'Directorio con saldos y vencimientos', icon: cilPeople },
    inventory: { hint: 'Productos, precios, planes y stock', icon: cilBasket },
};

function request(type: ExportType, filters: Readonly<Record<string, string>> = {}) {
    router.post(EXPORTS_URL, { type, filters }, { preserveScroll: true });
}

export default function ExportsIndex({ exports, canExport, types }: ExportsPageProps) {
    return (
        <BackofficeLayout title="Exportaciones" description="Descarga tus datos en Excel. Te avisamos por correo cuando el archivo esté listo.">
            {!canExport ? <UpgradePrompt feature="excel_export" /> : null}

            <div className="grid gap-3 md:grid-cols-3">
                {types
                    .filter((type) => type.allowed)
                    .map((type) => (
                        <section
                            key={type.value}
                            aria-label={type.label}
                            className="bg-base-100 border-line shadow-card flex items-center gap-3.5 rounded-2xl border p-4"
                        >
                            <IconChip icon={SOURCES[type.value].icon} tone="primary" size={40} />
                            <div className="flex min-w-0 flex-1 flex-col">
                                <h2 className="text-[15px] font-bold">{type.label}</h2>
                                <p className="text-ink-3 text-xs">{SOURCES[type.value].hint}</p>
                            </div>
                            <Button
                                size="sm"
                                variant="soft"
                                disabled={!canExport}
                                onClick={() => request(type.value)}
                                aria-label={`Exportar ${type.label}`}
                            >
                                <Icon icon={cilCloudDownload} size={14} />
                                Exportar
                            </Button>
                        </section>
                    ))}
            </div>

            {exports.length === 0 ? (
                <Alert tone="info">
                    Todavía no has exportado nada. Pide un Excel aquí o desde la cobranza, los clientes o el inventario, con sus filtros.
                </Alert>
            ) : (
                <div className="bg-base-100 border-line shadow-card overflow-x-auto rounded-2xl border">
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
                                <ExportTableRow key={row.id} row={row} canExport={canExport} />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <p className="text-ink-3 text-xs">Solo puede haber una exportación en curso por tipo. Los archivos se borran a los siete días.</p>
        </BackofficeLayout>
    );
}

function ExportTableRow({ row, canExport }: { row: ExportRow; canExport: boolean }) {
    return (
        <tr>
            <td className="font-semibold">{row.typeLabel}</td>
            <td>
                <Badge tone={TONES[row.status]}>{row.statusLabel}</Badge>
            </td>
            <td className="text-ink-2 whitespace-nowrap">
                {row.waitingMinutes !== null ? `hace ${row.waitingMinutes} min` : formatMoment(row.requestedIso)}
            </td>
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
                    <Button size="sm" variant="secondary" disabled={!canExport} onClick={() => request(row.type, row.retryFilters ?? {})}>
                        <Icon icon={cilReload} size={14} />
                        Reintentar
                    </Button>
                ) : null}
            </td>
        </tr>
    );
}

function Availability({ row }: { row: ExportRow }) {
    if (row.status === 'expired') {
        return <>Se borró a los siete días</>;
    }

    if (row.status === 'failed') {
        return <span className="text-error">{row.errorMessage ?? 'No se pudo generar.'}</span>;
    }

    if (row.status === 'completed' && row.expiresIso !== null) {
        return <>hasta {formatMoment(row.expiresIso)}</>;
    }

    return <>—</>;
}
