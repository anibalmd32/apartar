import { PrimaryAmount } from '@/features/catalog/components/PrimaryAmount';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { caracasToday, shortDay } from '@/features/marketing/board/board-math';
import { type ProductDetail } from '@/types/catalog-view';

export type PriceOffer = Pick<ProductDetail, 'price' | 'compareAtPrice' | 'savingsPercent'>;

export type PlanOffer = Pick<ProductDetail, 'downPayment' | 'installmentAmount' | 'lastInstallmentAmount' | 'installmentsCount' | 'frequencyDays'>;

const VISIBLE_ROWS = 5;

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : days === 7 ? 'cada semana' : days === 15 ? 'cada quincena' : `cada ${days} días`;
}

function addDays(iso: string, days: number): string {
    const [year, month, day] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(year!, month! - 1, day! + days));

    return date.toISOString().slice(0, 10);
}

export function PriceTag({ product }: { product: PriceOffer }) {
    return (
        <div className="flex flex-wrap items-start gap-x-2.5 gap-y-1">
            <MoneyLabel
                money={product.price}
                stacked
                className="text-[2.125rem] leading-[1.15] font-extrabold [font-stretch:88%] @3xl:text-[2.5rem]"
            />
            {product.compareAtPrice !== null ? (
                <s className="text-ink-3 mt-3 text-lg @3xl:mt-4">
                    <span className="sr-only">Antes </span>
                    <PrimaryAmount money={product.compareAtPrice} />
                </s>
            ) : null}
            {product.savingsPercent ? (
                <span className="text-success mt-3 text-[0.8125rem] font-bold whitespace-nowrap @3xl:mt-4">Ahorras {product.savingsPercent}%</span>
            ) : null}
        </div>
    );
}

interface Row {
    key: string;
    when: string;
    label: string;
    amount: ProductDetail['downPayment'];
}

function PlanRow({ row }: { row: Row }) {
    return (
        <li className="pz-rule-dashed grid grid-cols-[5.5rem_1fr_auto] items-baseline gap-3 py-2">
            <span className="font-hand text-primary-ink text-lg leading-none">{row.when}</span>
            <span className="text-ink-2 text-[0.9375rem]">{row.label}</span>
            <PrimaryAmount money={row.amount} className="text-[0.9375rem] font-bold tabular-nums" />
        </li>
    );
}

export function PlanBreakdown({ product }: { product: PlanOffer }) {
    const installment = product.installmentAmount;

    if (installment === null) {
        return null;
    }

    const today = caracasToday();
    const requiresDownPayment = Number(product.downPayment.cents) > 0;
    const count = product.installmentsCount;

    const rows: Row[] = [
        ...(requiresDownPayment ? [{ key: 'inicial', when: 'Hoy', label: 'Inicial', amount: product.downPayment }] : []),
        ...Array.from({ length: count }, (_, index) => {
            const last = index === count - 1 && product.lastInstallmentAmount !== null;

            return {
                key: `cuota-${index + 1}`,
                when: shortDay(addDays(today, (index + 1) * product.frequencyDays)),
                label: `Cuota ${index + 1}`,
                amount: last && product.lastInstallmentAmount !== null ? product.lastInstallmentAmount : installment,
            };
        }),
    ];

    const shown = rows.slice(0, VISIBLE_ROWS);
    const rest = rows.slice(VISIBLE_ROWS);

    return (
        <section aria-labelledby="plan-heading" className="flex flex-col gap-2">
            <h2 id="plan-heading" className="text-[1.0625rem] leading-snug font-bold">
                {requiresDownPayment ? (
                    <>
                        Apártalo hoy con <MoneyLabel money={product.downPayment} /> y paga el resto en {count} {count === 1 ? 'cuota' : 'cuotas'}{' '}
                        {frequencyLabel(product.frequencyDays)}
                    </>
                ) : (
                    <>
                        Apártalo hoy sin inicial y págalo en {count} {count === 1 ? 'cuota' : 'cuotas'} {frequencyLabel(product.frequencyDays)}
                    </>
                )}
            </h2>

            <ol className="flex flex-col" aria-label="Plan de pagos si apartas hoy">
                {shown.map((row) => (
                    <PlanRow key={row.key} row={row} />
                ))}
            </ol>

            {rest.length > 0 ? (
                <details className="group">
                    <summary className="text-primary-ink touch:min-h-11 flex min-h-9 cursor-pointer list-none items-center text-[0.875rem] font-bold [&::-webkit-details-marker]:hidden">
                        <span className="group-open:hidden">Ver las {rest.length} cuotas restantes</span>
                        <span className="hidden group-open:inline">Ocultar</span>
                    </summary>
                    <ol className="flex flex-col">
                        {rest.map((row) => (
                            <PlanRow key={row.key} row={row} />
                        ))}
                    </ol>
                </details>
            ) : null}

            <p className="text-ink-3 text-[0.8125rem]">Las fechas son si apartas hoy; las definitivas quedan en tu plan.</p>
        </section>
    );
}
