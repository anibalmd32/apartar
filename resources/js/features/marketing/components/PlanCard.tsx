import { Icon } from '@/components/ui/icon';
import { SignupCta } from '@/features/marketing/components/SignupCta';
import { formatLimit } from '@/lib/plan';
import { cn } from '@/lib/utils';
import { type PricingPlan } from '@/types/marketing';
import { cilCheckCircle } from '@coreui/icons';
import { type ReactNode } from 'react';

interface PlanCardProps {
    plan: PricingPlan;
    previous?: PricingPlan;
    featured?: boolean;
}

export const FREE_PLAN_SUMMARY = 'Incluye el apartado completo: catálogo, cuotas y cobro a tus clientes.';

function Item({ featured, children }: { featured: boolean; children: ReactNode }) {
    return (
        <li className="flex items-start gap-2.5">
            <Icon icon={cilCheckCircle} className={cn('mt-0.5 shrink-0', featured ? 'text-primary-content' : 'text-success')} />
            <span className={featured ? '' : 'text-ink-2'}>{children}</span>
        </li>
    );
}

export function PlanCard({ plan, previous, featured = false }: PlanCardProps) {
    const muted = featured ? 'text-primary-content/85' : 'text-ink-2';
    const inherited = new Set(previous?.features ?? []);
    const extras = plan.features.filter((feature) => !inherited.has(feature));

    return (
        <li
            className={cn(
                'flex flex-col gap-[18px] rounded-2xl p-7',
                featured ? 'bg-primary text-primary-content' : 'border-line bg-base-100 shadow-card border',
            )}
        >
            <h2 className="text-xl font-bold">{plan.name}</h2>
            <p className="flex items-baseline gap-2">
                <span className="tracking-heading text-[34px] leading-tight font-extrabold tabular-nums">{plan.price}</span>
                {plan.period !== '' ? <span className={cn('text-[15px] font-medium', muted)}>{plan.period}</span> : null}
            </p>

            {plan.isFree ? (
                <SignupCta tone={featured ? 'inverse' : 'secondary'} />
            ) : (
                <div className="flex flex-col gap-2">
                    <SignupCta
                        tone={featured ? 'inverse' : 'secondary'}
                        href={route('register', { plan: plan.code })}
                        label={`Empezar con ${plan.name}`}
                    />
                    <p className={cn('text-[13px]', muted)}>Creas tu cuenta gratis y pasas a {plan.name} desde Suscripción, sin tarjeta.</p>
                </div>
            )}

            <ul className="flex flex-col gap-2.5 text-sm">
                {plan.limits.map((limit) => (
                    <Item key={limit.key} featured={featured}>
                        <strong className="font-semibold">{formatLimit(limit.limit)}</strong> {limit.label}
                    </Item>
                ))}
                {previous === undefined
                    ? plan.features.map((feature) => (
                          <Item key={feature} featured={featured}>
                              {feature}
                          </Item>
                      ))
                    : null}
            </ul>

            {previous !== undefined && extras.length > 0 ? (
                <div className={cn('flex flex-col gap-2.5 border-t pt-[18px]', featured ? 'border-primary-content/20' : 'border-line')}>
                    <p className="text-sm font-bold">Todo lo de {previous.name}, más:</p>
                    <ul className="flex flex-col gap-2.5 text-sm">
                        {extras.map((feature) => (
                            <Item key={feature} featured={featured}>
                                {feature}
                            </Item>
                        ))}
                    </ul>
                </div>
            ) : null}

            {plan.features.length === 0 ? <p className={cn('text-sm', muted)}>{FREE_PLAN_SUMMARY}</p> : null}
        </li>
    );
}
