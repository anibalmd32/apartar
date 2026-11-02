import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { ExportList } from '@/features/exports/components/ExportList';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { EXPORTS_URL, type ExportsPageProps, type ExportType } from '@/types/exports';
import { cilBasket, cilCloudDownload, cilDollar, cilPeople, cilWallet } from '@coreui/icons';
import { router } from '@inertiajs/react';

const SOURCES: Record<ExportType, { hint: string; icon: string[] }> = {
    collections: { hint: 'Cuotas por cobrar con el filtro que elijas', icon: cilWallet },
    customers: { hint: 'Directorio con saldos y vencimientos', icon: cilPeople },
    inventory: { hint: 'Productos, precios, planes y stock', icon: cilBasket },
    payments: { hint: 'Cada pago con lo declarado, la tasa y el monto en USD', icon: cilDollar },
};

function request(type: ExportType, filters: Readonly<Record<string, string>> = {}) {
    router.post(EXPORTS_URL, { type, filters }, { preserveScroll: true });
}

export default function ExportsIndex({ exports, canExport, types }: ExportsPageProps) {
    return (
        <BackofficeLayout title="Exportaciones" description="Descarga tus datos en Excel. Te avisamos por correo cuando el archivo esté listo.">
            {!canExport ? <UpgradePrompt feature="excel_export" /> : null}

            <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
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
                <ExportList exports={exports} canExport={canExport} onRetry={request} />
            )}

            <p className="text-ink-3 text-xs">Solo puede haber una exportación en curso por tipo. Los archivos se borran a los siete días.</p>
        </BackofficeLayout>
    );
}
