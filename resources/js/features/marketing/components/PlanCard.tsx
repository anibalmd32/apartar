import { Icon } from '@/components/ui/icon';
import { formatLimit } from '@/lib/plan';
import { cn } from '@/lib/utils';
import { type PricingPlan } from '@/types/marketing';
import { cilCheckCircle } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface PlanCardProps {
    plan: PricingPlan;
    featured?: boolean;
}

export function PlanCard({ plan, featured = false }: PlanCardProps) {
    const muted = featured ? 'text-primary-content/85' : 'text-ink-2';

    return (
        <li
            className={cn(
                'flex flex-col gap-[18px] rounded-2xl p-7',
                featured ? 'bg-primary text-primary-content' : 'border-line bg-base-100 shadow-card border',
            )}
        >
            <h2 className="text-xl font-bold">{plan.name}</h2>
            <p className="text-[34px] leading-tight font-extrabold tracking-[-0.04em]">{plan.priceLabel}</p>

            {plan.isFree ? (
                <Link href={route('register')} className={cn('btn h-12 rounded-full text-[15px]', featured && 'border-0 bg-white text-[#4338ca]')}>
                    Empezar gratis
                </Link>
            ) : null}

            <ul className="flex flex-col gap-2.5 text-sm">
                {plan.limits.map((limit) => (
                    <li key={limit.key} className="flex items-start gap-2.5">
                        <Icon icon={cilCheckCircle} className={cn('mt-0.5', featured ? 'text-primary-content' : 'text-success')} />
                        <span className={featured ? '' : 'text-ink-2'}>
                            <strong className="font-semibold">{formatLimit(limit.limit)}</strong> {limit.label}
                        </span>
                    </li>
                ))}
                {plan.features.map((feature) => (
                    <li key={feature} className="flex items-start gap-2.5">
                        <Icon icon={cilCheckCircle} className={cn('mt-0.5', featured ? 'text-primary-content' : 'text-success')} />
                        <span className={featured ? '' : 'text-ink-2'}>{feature}</span>
                    </li>
                ))}
            </ul>

            {plan.features.length === 0 ? <p className={cn('text-sm', muted)}>Sin funciones de pago: lo esencial para empezar a vender.</p> : null}
        </li>
    );
}
