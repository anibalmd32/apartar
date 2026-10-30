import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { hasVes } from '@/lib/money';
import { cn } from '@/lib/utils';
import { type LayawayPlanView } from '@/types/layaway';
import { type MoneyView } from '@/types/money';
import { type ImageSet } from '@/types/product';

interface LayawaySummaryCardProps {
    productName: string;
    image: ImageSet | null;
    unitPrice?: MoneyView;
    plan: LayawayPlanView;
    stale?: boolean;
    className?: string;
}

function Row({ label, money, strong = false }: { label: string; money: MoneyView; strong?: boolean }) {
    return (
        <div className="flex items-baseline justify-between gap-4 py-1.5">
            <dt className="text-ink-2 text-[13px]">{label}</dt>
            <dd className="text-right">
                <MoneyLabel money={money} stacked className={strong ? 'text-primary-ink text-[17px] font-extrabold' : 'text-sm font-bold'} />
            </dd>
        </div>
    );
}

export function LayawaySummaryCard({ productName, image, unitPrice, plan, stale = false, className }: LayawaySummaryCardProps) {
    const { isVesPrimary } = useCurrencyDisplay();
    const units = plan.quantity === 1 ? '1 unidad' : `${plan.quantity} unidades`;
    const price = unitPrice === undefined ? null : isVesPrimary && hasVes(unitPrice) ? unitPrice.ves : unitPrice.usd;

    return (
        <section
            aria-label="Tu plan de pago"
            aria-busy={stale}
            className={cn('bg-base-100 border-line shadow-card flex flex-col gap-4 rounded-2xl border p-5', stale && 'opacity-60', className)}
        >
            <div className="border-line flex items-center gap-3 border-b pb-4">
                <div className="size-16 shrink-0 overflow-hidden rounded-xl">
                    {image !== null ? (
                        <img src={image.thumb} alt="" className="h-full w-full object-cover" />
                    ) : (
                        <ProductPlaceholder name={productName} className="text-2xl" />
                    )}
                </div>
                <div className="min-w-0">
                    <h2 className="text-base font-bold">{productName}</h2>
                    <p className="text-ink-3 text-[13px]">{price === null ? units : `${units} · ${price}`}</p>
                </div>
            </div>

            <dl>
                <Row label="Total" money={plan.total} />
                {plan.requiresDownPayment ? (
                    <Row label="Inicial (pagas ahora)" money={plan.downPayment} strong />
                ) : (
                    <div className="flex items-baseline justify-between gap-4 py-1.5">
                        <dt className="text-ink-2 text-[13px]">Inicial</dt>
                        <dd className="text-primary-ink text-right text-sm font-bold">Sin inicial</dd>
                    </div>
                )}
                <Row label="Financiado" money={plan.financedAmount} />
                {plan.lastInstallmentAmount !== null ? <Row label="Última cuota" money={plan.lastInstallmentAmount} /> : null}
            </dl>

            {plan.installments.length > 0 ? (
                <div className="flex flex-col gap-1">
                    <h3 className="text-eyebrow text-ink-3">Tus cuotas</h3>
                    <ol className="flex flex-col">
                        {plan.installments.map((installment) => (
                            <li key={installment.number} className="flex items-baseline justify-between gap-4 py-1.5 text-[13px]">
                                <span className="text-ink-2 flex items-baseline gap-2">
                                    <span aria-hidden="true" className="bg-primary size-1.5 shrink-0 -translate-y-0.5 rounded-full" />
                                    Cuota {installment.number} · {plan.requiresDownPayment ? '~ ' : ''}
                                    {installment.estimatedDueDate}
                                </span>
                                <span className="shrink-0 text-right whitespace-nowrap">
                                    <MoneyLabel money={installment.amount} stacked className="font-semibold" />
                                </span>
                            </li>
                        ))}
                    </ol>
                    <p className="text-ink-3 text-xs">
                        {plan.requiresDownPayment
                            ? 'Las fechas cuentan desde que el negocio apruebe tu inicial; si la pagas otro día, se corren.'
                            : 'Sin inicial: el apartado arranca activo hoy y las fechas cuentan desde hoy.'}
                    </p>
                </div>
            ) : null}
        </section>
    );
}
