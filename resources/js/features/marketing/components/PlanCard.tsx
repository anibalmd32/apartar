import { Icon } from '@/components/ui/icon';
import { formatBs } from '@/features/marketing/board/board-math';
import { SignupCta } from '@/features/marketing/components/SignupCta';
import { formatLimit } from '@/lib/plan';
import { type PricingPlan } from '@/types/marketing';
import { cilCheckAlt } from '@coreui/icons';
import { type ReactNode } from 'react';

interface PlanCardProps {
    plan: PricingPlan;
    previous?: PricingPlan;
    featured?: boolean;
    rate?: string | null;
}

export const FREE_PLAN_SUMMARY = 'Incluye el apartado completo: catálogo, cuotas y cobro a tus clientes.';

function Feature({ children }: { children: ReactNode }) {
    return (
        <li className="flex items-start gap-2.5">
            <Icon icon={cilCheckAlt} size={18} className="text-pz-blue mt-1 shrink-0" />
            <span>{children}</span>
        </li>
    );
}

export function PlanCard({ plan, previous, featured = false, rate = null }: PlanCardProps) {
    const inherited = new Set(previous?.features ?? []);
    const extras = previous === undefined ? plan.features : plan.features.filter((feature) => !inherited.has(feature));

    return (
        <li className="flex flex-col gap-6 py-10 first:pt-0 lg:px-10 lg:py-0 lg:first:pl-0 lg:last:pr-0">
            <div className="flex flex-col gap-2">
                <h2 className="font-vinyl text-[2.5rem] lg:text-5xl">{plan.name}</h2>
                <p className="flex flex-wrap items-baseline gap-x-3">
                    <span className={featured ? 'font-vinyl text-pz-blue text-[4.5rem]' : 'font-vinyl text-[4.5rem]'}>{plan.price}</span>
                    {plan.period !== '' ? <span className="font-hand text-pz-ink-2 text-2xl">{plan.period}</span> : null}
                </p>
                {rate !== null && !plan.isFree ? (
                    <p className="font-hand text-pz-blue text-xl">= {formatBs(plan.priceCents, rate)} a la tasa de hoy</p>
                ) : null}
            </div>

            {plan.isFree ? (
                <SignupCta tone={featured ? 'primary' : 'secondary'} className="self-start max-sm:w-full" />
            ) : (
                <div className="flex flex-col gap-2">
                    <SignupCta
                        tone={featured ? 'primary' : 'secondary'}
                        href={route('register', { plan: plan.code })}
                        label={`Empezar con ${plan.name}`}
                        className="self-start max-sm:w-full"
                    />
                    <p className="text-pz-ink-2 text-[0.9375rem]">Creas tu cuenta gratis y pasas a {plan.name} desde Suscripción, sin tarjeta.</p>
                </div>
            )}

            {previous !== undefined && extras.length > 0 ? (
                <p className="font-hand text-pz-ink-3 -mb-3 text-xl">Todo lo de {previous.name}, más:</p>
            ) : null}
            {extras.length > 0 ? (
                <ul className="flex flex-col gap-2 text-[1.0625rem]">
                    {extras.map((feature) => (
                        <Feature key={feature}>{feature}</Feature>
                    ))}
                </ul>
            ) : null}
            {plan.features.length === 0 ? <p className="text-pz-ink-2 text-[1.0625rem]">{FREE_PLAN_SUMMARY}</p> : null}

            {plan.limits.length > 0 ? (
                <div>
                    <p className="font-hand text-pz-ink-3 text-xl">Hasta</p>
                    <dl className="mt-1 flex flex-col">
                        {plan.limits.map((limit) => (
                            <div key={limit.key} className="pz-rule-dashed flex items-baseline justify-between gap-4 py-1.5 text-[0.9375rem]">
                                <dt className="text-pz-ink-2">{limit.label}</dt>
                                <dd className="font-bold tabular-nums">{formatLimit(limit.limit)}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            ) : null}
        </li>
    );
}
