import { Button } from '@/components/ui/button';
import { DateInput } from '@/components/ui/date-input';
import { SectionCard } from '@/components/ui/section-card';
import { shiftIsoDate, todayIso } from '@/lib/iso-date';
import { type Method } from '@inertiajs/core';
import { useForm, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

export interface CourtesyState {
    until: string | null;
    restoresTo: string | null;
}

interface SubscriptionActionsProps {
    slug: string;
    plans: readonly { code: string; name: string }[];
    currentPlan: string | null;
    courtesy?: CourtesyState | null;
    invoiceable?: boolean;
}

function dayMonthYear(date: string | null): string {
    return date === null ? 'sin fecha de fin' : `${date.slice(8, 10)}/${date.slice(5, 7)}/${date.slice(0, 4)}`;
}

function ReasonForm({
    title,
    children,
    url,
    method,
    label,
    errorKey,
}: {
    title: string;
    children: ReactNode;
    url: string;
    method: Method;
    label: string;
    errorKey: string;
}) {
    const form = useForm({ reason: '' });
    const errors = usePage().props.errors as Record<string, string | undefined>;

    return (
        <form
            className="flex flex-col gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(method, url, { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <h3 className="text-[13px] font-semibold">{title}</h3>
            <p className="text-ink-2">{children}</p>
            <input
                className="input w-full"
                aria-label={`Motivo: ${title.toLowerCase()}`}
                placeholder="Motivo"
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            {form.errors.reason ? <p className="text-error">{form.errors.reason}</p> : null}
            {errors[errorKey] ? <p className="text-error">{errors[errorKey]}</p> : null}
            <Button type="submit" variant="secondary" size="sm" loading={form.processing} className="self-start">
                {label}
            </Button>
        </form>
    );
}

export function SubscriptionActions({ slug, plans, currentPlan, courtesy = null, invoiceable = false }: SubscriptionActionsProps) {
    const plan = useForm({ plan: currentPlan ?? '', reason: '' });
    const grant = useForm({ until: '', reason: '' });
    const tomorrow = shiftIsoDate(todayIso(), 1);
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
                            grant.post(`${base}/cortesia`, { onSuccess: () => grant.reset() });
                        }}
                    >
                        <h3 className="text-[13px] font-semibold">Conceder cortesía</h3>
                        <DateInput ariaLabel="Hasta" min={tomorrow} value={grant.data.until} onChange={(value) => grant.setData('until', value)} />
                        <input
                            className="input w-full"
                            aria-label="Motivo de la cortesía"
                            placeholder="Motivo"
                            value={grant.data.reason}
                            onChange={(event) => grant.setData('reason', event.target.value)}
                        />
                        {grant.errors.until ? <p className="text-error">{grant.errors.until}</p> : null}
                        {grant.errors.reason ? <p className="text-error">{grant.errors.reason}</p> : null}
                        <Button type="submit" variant="secondary" size="sm" loading={grant.processing} className="self-start">
                            Extender período
                        </Button>
                    </form>

                    {courtesy ? (
                        <ReasonForm title="Revocar cortesía" url={`${base}/cortesia`} method="delete" label="Revocar cortesía" errorKey="courtesy">
                            Hay una cortesía hasta el {dayMonthYear(courtesy.until)}. Al revocarla, el período vuelve a{' '}
                            {courtesy.restoresTo ? 'terminar el ' : ''}
                            {dayMonthYear(courtesy.restoresTo)}.
                        </ReasonForm>
                    ) : null}

                    {invoiceable ? (
                        <ReasonForm title="Emitir factura" url={`${base}/facturas`} method="post" label="Emitir factura" errorKey="invoice">
                            Factura un mes del plan actual desde el fin del período. El dueño recibe el aviso y la paga con el flujo normal.
                        </ReasonForm>
                    ) : null}
                </div>
            </SectionCard>
        </div>
    );
}
