import { Alert } from '@/components/ui/alert';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type ExportRow, type ExportsPageProps, type ExportStatus } from '@/types/exports';

const BADGES: Record<ExportStatus, string> = {
    queued: 'badge-ghost',
    processing: 'badge-info',
    completed: 'badge-success',
    failed: 'badge-error',
    expired: 'badge-ghost',
};

/**
 * Las exportaciones a Excel (`REQ-BOF-043`, `T-070`): cada una con su estado,
 * cuánto lleva esperando si sigue en cola (SP-10), el motivo si falló (SP-2),
 * y el enlace firmado si está lista. Se piden desde la cobranza, los clientes
 * y el inventario; aquí se descargan. Lo ya generado se descarga aunque el
 * plan ya no incluya la función (SP-12).
 */
export default function ExportsIndex({ exports, canExport }: ExportsPageProps) {
    return (
        <BackofficeLayout title="Exportaciones" description="Los archivos de Excel que has pedido. Se guardan siete días.">
            {!canExport ? <UpgradePrompt feature="excel_export" /> : null}

            {exports.length === 0 ? (
                <Alert tone="info">
                    Todavía no has exportado nada. Pide un Excel desde la cobranza, los clientes o el inventario, con los filtros que estés usando.
                </Alert>
            ) : (
                <ul className="flex flex-col gap-2">
                    {exports.map((row) => (
                        <li key={row.id}>
                            <ExportCard row={row} />
                        </li>
                    ))}
                </ul>
            )}
        </BackofficeLayout>
    );
}

function ExportCard({ row }: { row: ExportRow }) {
    return (
        <article className="card bg-base-100 shadow-sm">
            <div className="card-body flex-row flex-wrap items-center justify-between gap-2 py-4">
                <div className="flex flex-col gap-1">
                    <p className="font-medium">
                        {row.typeLabel} <span className={`badge ${BADGES[row.status]} ml-2`}>{row.statusLabel}</span>
                    </p>
                    <p className="text-sm opacity-70">
                        Pedida el {row.requestedAt}
                        {row.rowCount !== null ? ` · ${row.rowCount} filas` : ''}
                        {row.expiresAt !== null && row.status === 'completed' ? ` · disponible hasta el ${row.expiresAt}` : ''}
                        {row.waitingMinutes !== null ? ` · esperando desde hace ${row.waitingMinutes} min` : ''}
                    </p>
                    {row.errorMessage !== null ? <p className="text-error text-sm">{row.errorMessage}</p> : null}
                    {row.status === 'expired' ? (
                        <p className="text-sm opacity-70">El archivo se borró a los siete días. Pídelo otra vez si lo necesitas.</p>
                    ) : null}
                </div>
                {row.downloadUrl !== null ? (
                    <a className="btn btn-sm btn-primary" href={row.downloadUrl}>
                        Descargar
                    </a>
                ) : null}
            </div>
        </article>
    );
}
