import { Alert } from '@/components/ui/alert';
import { PlansLink } from '@/features/plan/components/PlansLink';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { type PlanFeature, type PlanSharedProps } from '@/types/plan';
import { usePage } from '@inertiajs/react';

/**
 * Lo que se pierde y cómo se consigue (`REQ-SUB-004` RB-5, RB-7, SP-3,
 * `T-067`). Dos usos con la misma forma:
 *
 *  - **con `feature`**, junto a un control de pago: si el plan no la incluye,
 *    el control se muestra deshabilitado y esto dice por qué y qué plan la
 *    trae. **Se muestra, no se oculta** (RB-7): el negocio tiene que poder ver
 *    lo que se está perdiendo;
 *  - **sin nada**, en el layout: el último rechazo del servidor —el `422` de
 *    un cupo o el `403` de una función—, con su mensaje, la salida sin cambiar
 *    de plan y el enlace a Suscripción.
 *
 * No decide nada (RB-1): el mensaje, el plan mínimo y la sugerencia son del
 * servidor.
 */
export function UpgradePrompt({ feature }: { feature?: PlanFeature }) {
    if (feature !== undefined) {
        return <FeaturePrompt feature={feature} />;
    }

    return <LastRejection />;
}

function FeaturePrompt({ feature }: { feature: PlanFeature }) {
    const plan = usePlanLimits();

    if (plan.hasFeature(feature)) {
        return null;
    }

    const upgrade = plan.featureUpgrade(feature);

    return (
        <p className="text-sm opacity-80">
            {plan.featureLabel(feature)}: no está en tu plan
            {upgrade !== null ? `, la incluye ${upgrade.planName}` : ''}. <PlansLink className="link" />
        </p>
    );
}

function LastRejection() {
    const { planLimit, planFeature, planBlocked } = usePage<Partial<PlanSharedProps>>().props;
    const rejection = planLimit ?? planFeature ?? planBlocked ?? null;

    if (rejection === null) {
        return null;
    }

    const suggestion = planLimit !== null && planLimit !== undefined ? planLimit.limit.suggestion : null;

    return (
        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
            <span>
                {rejection.message}
                {suggestion !== null ? ` ${suggestion}` : null}
            </span>

            <PlansLink href={rejection.upgradeUrl} />
        </Alert>
    );
}
