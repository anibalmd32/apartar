import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/ui/section-card';
import { useForm } from '@inertiajs/react';

interface SubscriptionActionsProps {
    slug: string;
    plans: readonly { code: string; name: string }[];
    currentPlan: string | null;
}

export function SubscriptionActions({ slug, plans, currentPlan }: SubscriptionActionsProps) {
    const plan = useForm({ plan: currentPlan ?? '', reason: '' });
    const courtesy = useForm({ until: '', reason: '' });
    const base = `/plataforma/organizaciones/${slug}`;

    return (
        <div id="suscripcion" className="scroll-mt-24">
            <SectionCard title="Suscripción" aria-label="Suscripción">
                <div className="grid gap-5 text-sm">
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            plan.post(`${base}/plan`, { onSuccess: () => plan.reset('reason') });
                        }}
                    >
                        <h3 className="text-[13px] font-semibold">Cambiar el plan</h3>
                        <select
                            className="select w-full"
                            aria-label="Plan"
                            value={plan.data.plan}
                            onChange={(event) => plan.setData('plan', event.target.value)}
                        >
                            {plans.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                        <input
                            className="input w-full"
                            aria-label="Motivo del cambio"
                            placeholder="Motivo"
                            value={plan.data.reason}
                            onChange={(event) => plan.setData('reason', event.target.value)}
                        />
                        {plan.errors.reason ? <p className="text-error">{plan.errors.reason}</p> : null}
                        <Button type="submit" variant="secondary" size="sm" loading={plan.processing} className="self-start">
                            Cambiar plan
                        </Button>
                    </form>

                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            courtesy.post(`${base}/cortesia`, { onSuccess: () => courtesy.reset() });
                        }}
                    >
                        <h3 className="text-[13px] font-semibold">Conceder cortesía</h3>
                        <input
                            type="date"
                            className="input w-full"
                            aria-label="Hasta"
                            value={courtesy.data.until}
                            onChange={(event) => courtesy.setData('until', event.target.value)}
                        />
                        <input
                            className="input w-full"
                            aria-label="Motivo de la cortesía"
                            placeholder="Motivo"
                            value={courtesy.data.reason}
                            onChange={(event) => courtesy.setData('reason', event.target.value)}
                        />
                        {courtesy.errors.until ? <p className="text-error">{courtesy.errors.until}</p> : null}
                        {courtesy.errors.reason ? <p className="text-error">{courtesy.errors.reason}</p> : null}
                        <Button type="submit" variant="secondary" size="sm" loading={courtesy.processing} className="self-start">
                            Extender período
                        </Button>
                    </form>
                </div>
            </SectionCard>
        </div>
    );
}
