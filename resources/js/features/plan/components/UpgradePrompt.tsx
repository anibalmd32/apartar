import { Alert } from '@/components/ui/alert';
import { PlansLink } from '@/features/plan/components/PlansLink';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { type PlanFeature, type PlanSharedProps } from '@/types/plan';
import { usePage } from '@inertiajs/react';

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
