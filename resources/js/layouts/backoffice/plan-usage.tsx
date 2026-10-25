import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export function PlanUsage({ className }: { className?: string }) {
    const plan = usePlanLimits();
    const usage = plan.usage('products');

    if (plan.state === null) {
        return null;
    }

    const near = plan.isNearLimit('products');
    const percentage = usage?.percentage ?? 0;

    const body = (
        <>
            <span className="flex items-center justify-between gap-2">
                <span className="text-[13px] font-bold">Uso del plan</span>
                <span className="text-primary-ink text-xs font-bold">{plan.state.planName}</span>
            </span>
            {usage ? (
                <>
                    <span className="text-ink-3 text-xs">
                        {usage.limit === null ? `${usage.used} productos · sin límite` : `${usage.used} de ${usage.limit} productos`}
                    </span>
                    {usage.limit === null ? null : (
                        <span className="bg-base-300 block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                            <span
                                className={cn('block h-full rounded-full', near ? 'bg-warning' : 'bg-primary')}
                                style={{ width: `${Math.min(100, Math.max(percentage, usage.used > 0 ? 3 : 0))}%` }}
                            />
                        </span>
                    )}
                </>
            ) : null}
        </>
    );

    const classes = cn('bg-surface-2 flex flex-col gap-2.5 rounded-xl p-3.5', className);

    return plan.canOpenSubscription ? (
        <Link href="/backoffice/suscripcion" className={cn(classes, 'hover:bg-base-300 transition-colors')}>
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}
