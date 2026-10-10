import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { SUBSCRIPTION_URL } from '@/types/plan';
import { Link } from '@inertiajs/react';

/**
 * «Ver planes», o a quien no puede abrir Suscripción, a quién pedírselo
 * (`T-067`): un enlace que da `403` es peor que ninguno.
 */
export function PlansLink({ className = 'btn btn-sm btn-warning', href = SUBSCRIPTION_URL }: { className?: string; href?: string }) {
    const plan = usePlanLimits();

    if (!plan.canOpenSubscription) {
        return <span className="text-sm opacity-80">Pídele al dueño del negocio que revise el plan.</span>;
    }

    return (
        <Link href={href} className={className}>
            Ver planes
        </Link>
    );
}
