import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { describeExcess, formatPlanDate } from '@/lib/plan-change';
import { type PlanOption } from '@/types/plan';

/**
 * Lo que cambia al elegir un plan, **antes** de confirmar (`REQ-SUB-005`
 * RB-5, `REQ-SUB-008` RB-9, `T-068`): al subir, lo que se gana y que aplica
 * ya; al bajar, desde cuándo, lo que queda por encima de cada techo y qué
 * pasará con ello, los catálogos que pasan de su techo de productos y las
 * funciones que se pierden.
 *
 * Todo viene calculado en `PlanChangePreview`, con el mismo contador con el
 * que decide el servidor (`REQ-SUB-004` RB-1).
 */
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
                            {describeExcess(excess, plan.metricLabel(excess.metric), option.name, preview.effectiveAt, timeZone)}
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
        </div>
    );
}
