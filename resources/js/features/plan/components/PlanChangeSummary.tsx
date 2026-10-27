import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { describeExcess, formatPlanDate } from '@/lib/plan-change';
import { type PlanOption } from '@/types/plan';

export function PlanChangeSummary({ option, timeZone }: { option: PlanOption; timeZone?: string }) {
    const plan = usePlanLimits();
    const { preview } = option;

    if (preview.direction === 'same') {
        return null;
    }

    if (preview.direction === 'upgrade') {
        return (
            <div className="text-sm">
                <p>Sus límites y funciones aplican en cuanto lo confirmes.</p>
                {plan.state?.status === 'suspended' ? (
                    <p>Tu suscripción sigue suspendida hasta que se apruebe el pago: hasta entonces no podrás crear nada nuevo.</p>
                ) : null}
                {preview.gainedFeatures.length > 0 ? (
                    <ul className="mt-1 list-inside list-disc">
                        {preview.gainedFeatures.map((feature) => (
                            <li key={feature}>{plan.featureLabel(feature)}</li>
                        ))}
                    </ul>
                ) : null}
            </div>
        );
    }

    const perCatalog = preview.productsPerCatalog;

    return (
        <div className="flex flex-col gap-2 text-sm">
            <p>
                El cambio se programa para el {preview.effectiveAt === null ? 'fin del período' : formatPlanDate(preview.effectiveAt, timeZone)}y
                hasta que se aplique conservas tu plan actual y puedes revertirlo desde aquí.
            </p>

            {preview.excesses.length === 0 && perCatalog === null ? <p>Todo lo que tienes cabe en {option.name}.</p> : null}

            {preview.excesses.length > 0 ? (
                <ul className="list-inside list-disc">
                    {preview.excesses.map((excess) => (
                        <li key={excess.metric}>
                            {describeExcess(excess, plan.metricLabel(excess.metric, excess.used), option.name, preview.unpublishAt, timeZone)}
                        </li>
                    ))}
                </ul>
            ) : null}

            {perCatalog !== null ? (
                <p>
                    {perCatalog.count === 1 ? 'Un catálogo tiene' : `${perCatalog.count} catálogos tienen`} más de {perCatalog.limit} productos (
                    {perCatalog.catalogs.map((catalog) => `${catalog.name}: ${catalog.products}`).join(', ')}
                    {perCatalog.count > perCatalog.catalogs.length ? '…' : ''}): se quedan como están, pero no podrás añadirles más.
                </p>
            ) : null}

            {preview.lostFeatures.length > 0 ? (
                <p>
                    Dejarás de tener: {preview.lostFeatures.map((feature) => plan.featureLabel(feature)).join(', ')}. Su configuración se guarda por
                    si vuelves a subir.
                </p>
            ) : null}
            {preview.retentionLoss ? (
                <p role="alert" className="text-warning">
                    El detalle de la analítica se guardará {preview.retentionLoss.toDays} días en lugar de {preview.retentionLoss.fromDays}: lo más
                    antiguo se borrará en la siguiente purga. Los totales de cada mes se conservan.
                </p>
            ) : null}
        </div>
    );
}
