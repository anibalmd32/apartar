import { Badge } from '@/components/ui/badge';
import { HealthCard, HealthRow } from '@/features/platform/components/HealthCard';
import PlatformLayout from '@/layouts/platform-layout';
import { type SystemHealth } from '@/types/platform';
import { cilBalanceScale, cilLayers, cilSpeedometer, cilStorage, cilSwapHorizontal } from '@coreui/icons';

const DASH = '—';

export default function Health({ health }: { health: SystemHealth }) {
    const { queue, exchangeRate, scheduledJobs, storage, realtime, reconciliation } = health;
    const time = new Intl.DateTimeFormat('es', { dateStyle: 'short', timeStyle: 'short' });

    const checks = [
        reconciliation === null ? null : reconciliation.discrepancies === 0,
        queue === null ? null : queue.failed === 0,
        exchangeRate === null ? null : !exchangeRate.isStale,
        realtime.connected,
        scheduledJobs === null ? null : scheduledJobs.every((job) => !job.isOverdue),
    ];
    const warnings = checks.filter((check) => check === false).length;

    return (
        <PlatformLayout
            title="Salud del sistema"
            description="Solo lectura. Lo que conviene saber antes de que lo diga un cliente."
            actions={
                <Badge tone={warnings === 0 ? 'success' : 'warning'}>
                    {warnings === 0 ? 'Todo operativo' : `${warnings} ${warnings === 1 ? 'aviso' : 'avisos'}`}
                </Badge>
            }
        >
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <HealthCard title="Reconciliación" icon={cilBalanceScale} ok={checks[0] ?? null} problem="Descuadres">
                    {reconciliation === null ? (
                        <HealthRow label="Estado">{DASH}</HealthRow>
                    ) : (
                        <>
                            <HealthRow label="Descuadres" tone={reconciliation.discrepancies > 0 ? 'error' : undefined}>
                                {reconciliation.discrepancies}
                            </HealthRow>
                            <HealthRow label="Sin corrección automática">{reconciliation.unresolved}</HealthRow>
                            <HealthRow label="De existencias">{reconciliation.stock}</HealthRow>
                            <HealthRow label="Última pasada">
                                {reconciliation.lastRunAt === null ? 'nunca' : time.format(new Date(reconciliation.lastRunAt))}
                            </HealthRow>
                            {reconciliation.discrepancies > 0 ? (
                                <code className="bg-surface-2 rounded-md px-2 py-1 text-xs">{reconciliation.command}</code>
                            ) : null}
                        </>
                    )}
                </HealthCard>

                <HealthCard title="Cola" icon={cilLayers} ok={checks[1] ?? null} problem="Fallidos">
                    {queue === null ? (
                        <HealthRow label="Estado">{DASH}</HealthRow>
                    ) : (
                        <>
                            <HealthRow label="Pendientes">{queue.pending ?? DASH}</HealthRow>
                            <HealthRow label="Fallidos" tone={queue.failed > 0 ? 'error' : undefined}>
                                {queue.failed}
                            </HealthRow>
                            <HealthRow label="Más antiguo">
                                {queue.oldestPendingMinutes === null ? DASH : `hace ${queue.oldestPendingMinutes} min`}
                            </HealthRow>
                        </>
                    )}
                </HealthCard>

                <HealthCard title="Tasa de cambio" icon={cilSwapHorizontal} ok={checks[2] ?? null} problem="Obsoleta">
                    {exchangeRate === null ? (
                        <HealthRow label="Estado">{DASH}</HealthRow>
                    ) : (
                        <>
                            <HealthRow label="Fuente">{exchangeRate.source}</HealthRow>
                            <HealthRow label="Antigüedad">
                                {exchangeRate.ageHours === null ? 'sin tasa' : `hace ${exchangeRate.ageHours} h`}
                            </HealthRow>
                            {exchangeRate.isStale ? (
                                <p className="text-error text-xs font-semibold">Obsoleta: las cifras en bolívares están desactualizadas.</p>
                            ) : null}
                        </>
                    )}
                </HealthCard>

                <HealthCard title="Tiempo real" icon={cilSpeedometer} ok={realtime.connected} problem="Degradado">
                    <HealthRow label="Reverb" tone={realtime.connected ? undefined : 'warning'}>
                        {realtime.connected ? 'Responde' : 'No responde: el chat sigue por sondeo'}
                    </HealthRow>
                </HealthCard>

                <HealthCard title="Almacenamiento" icon={cilStorage} ok={storage === null ? null : true}>
                    <HealthRow label="Usado">{storage === null ? DASH : `${storage.usedMb} MB`}</HealthRow>
                    <HealthRow label="Comprobantes">{storage === null ? DASH : storage.receiptsCount}</HealthRow>
                </HealthCard>
            </div>

            <section aria-label="Tareas programadas" className="table-frame">
                {scheduledJobs === null ? (
                    <p className="p-5">{DASH}</p>
                ) : (
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Tarea programada</th>
                                <th>Frecuencia</th>
                                <th>Última ejecución</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {scheduledJobs.map((job) => (
                                <tr key={job.command}>
                                    <td>
                                        <code className="text-[13px]">{job.command}</code>
                                    </td>
                                    <td className="text-ink-2">{job.frequency ?? '—'}</td>
                                    <td>{job.lastRunAt === null ? 'Sin registro' : time.format(new Date(job.lastRunAt))}</td>
                                    <td>{job.isOverdue ? <Badge tone="error">Atrasada</Badge> : <Badge tone="success">Al día</Badge>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </PlatformLayout>
    );
}
