import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { Icon } from '@/components/ui/icon';
import { PlanChangeSummary } from '@/features/plan/components/PlanChangeSummary';
import { UpgradeModal } from '@/features/plan/components/UpgradeModal';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { formatLimit } from '@/lib/plan';
import { cn } from '@/lib/utils';
import { CHANGE_PLAN_URL, type PlanOption, type UsageMetric } from '@/types/plan';
import { cilCheckAlt } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

const HEADLINE_METRICS: readonly UsageMetric[] = ['products', 'catalogs', 'active_layaways', 'users'];

function formatPrice(cents: number): string {
    return `$${(cents / 100).toLocaleString('es-VE', { maximumFractionDigits: cents % 100 === 0 ? 0 : 2 })}`;
}

export function PlanOptionCard({
    option,
    canChange,
    timeZone,
    needsDocument = false,
}: {
    option: PlanOption;
    canChange: boolean;
    timeZone?: string;
    needsDocument?: boolean;
}) {
    const plan = usePlanLimits();
    const [upgrading, setUpgrading] = useState(false);
    const viaModal = option.invoicePreview !== undefined && option.invoicePreview !== null;
    const { data, setData, post, processing, errors } = useForm<{ plan: string; confirmed: boolean }>({ plan: option.code, confirmed: false });
    const { preview } = option;
    const needsConfirmation = preview.direction === 'downgrade' && (preview.excesses.length > 0 || preview.productsPerCatalog !== null);
    const action = preview.direction === 'upgrade' ? `Subir a ${option.name}` : `Bajar a ${option.name}`;

    return (
        <article
            className={cn(
                'bg-base-100 shadow-card flex flex-col gap-4 rounded-2xl border p-5',
                option.isCurrent ? 'border-primary border-2' : 'border-line',
            )}
        >
            <header className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-lg font-bold">{option.name}</h3>
                {option.isCurrent ? <span className="badge badge-primary">Tu plan</span> : null}
                {option.isScheduled ? <span className="badge badge-info">Programado</span> : null}
            </header>

            <p className="flex items-baseline gap-1">
                <span className="text-[34px] leading-none font-extrabold tracking-[-1px]">{formatPrice(option.priceCents)}</span>
                <span className="text-ink-3 text-[13px]">/ mes</span>
            </p>

            <ul className="flex flex-col gap-2 text-[13px]">
                {HEADLINE_METRICS.map((metric) => (
                    <li key={metric} className="text-ink-2 flex items-center gap-2">
                        <Icon icon={cilCheckAlt} size={14} className="text-success" />
                        <span>
                            {formatLimit(option.limits[metric])} <span>{plan.metricLabel(metric)}</span>
                        </span>
                    </li>
                ))}
            </ul>

            <div className="mt-auto flex flex-col gap-2">
                {canChange && !option.isCurrent && !option.isScheduled ? (
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (viaModal) {
                                setUpgrading(true);

                                return;
                            }

                            post(CHANGE_PLAN_URL, { preserveScroll: true });
                        }}
                    >
                        <PlanChangeSummary option={option} timeZone={timeZone} />

                        {needsConfirmation ? (
                            <CheckboxField
                                label="Entiendo lo que queda por encima del límite"
                                checked={data.confirmed}
                                onChange={(event) => setData('confirmed', event.target.checked)}
                                error={errors.confirmed}
                            />
                        ) : null}

                        {errors.plan ? <p className="text-error text-sm">{errors.plan}</p> : null}

                        <Button
                            type="submit"
                            variant={preview.direction === 'upgrade' ? 'primary' : 'secondary'}
                            loading={processing}
                            disabled={needsConfirmation && !data.confirmed}
                        >
                            {action}
                        </Button>
                    </form>
                ) : option.isCurrent ? (
                    <span className="btn btn-disabled">Plan actual</span>
                ) : null}
            </div>

            {viaModal ? (
                <UpgradeModal
                    option={option}
                    open={upgrading}
                    onClose={() => setUpgrading(false)}
                    needsDocument={needsDocument}
                    timeZone={timeZone}
                />
            ) : null}
        </article>
    );
}
