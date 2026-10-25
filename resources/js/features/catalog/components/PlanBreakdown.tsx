import { PrimaryAmount } from '@/features/catalog/components/PrimaryAmount';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type ProductDetail } from '@/types/catalog-view';

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

export function PriceTag({ product }: { product: ProductDetail }) {
    return (
        <div className="flex items-start gap-2.5">
            <MoneyLabel money={product.price} stacked className="text-[34px] leading-[1.2] font-extrabold tracking-[-1px] lg:text-[40px]" />
            {product.compareAtPrice !== null ? (
                <s className="text-ink-3 mt-3 text-lg lg:mt-4">
                    <span className="sr-only">Antes </span>
                    <PrimaryAmount money={product.compareAtPrice} />
                </s>
            ) : null}
            {product.savingsPercent ? (
                <span className="bg-success-soft text-success mt-3 rounded-full px-2.5 py-0.5 text-xs font-bold whitespace-nowrap lg:mt-4">
                    Ahorras {product.savingsPercent}%
                </span>
            ) : null}
        </div>
    );
}

function Tile({ label, amount, hint, first = false }: { label: string; amount: ProductDetail['downPayment']; hint: string; first?: boolean }) {
    return (
        <li
            className={cn(
                'flex flex-col items-center gap-0.5 rounded-[10px] px-1.5 py-2 text-center',
                first ? 'bg-primary text-primary-content' : 'bg-base-100',
            )}
        >
            <span className={cn('text-[10px] font-semibold', first ? 'opacity-85' : 'text-ink-3')}>{label}</span>
            <PrimaryAmount money={amount} className="text-sm font-extrabold" />
            <span className={cn('text-[10px]', first ? 'opacity-85' : 'text-ink-3')}>{hint}</span>
        </li>
    );
}

export function PlanBreakdown({ product }: { product: ProductDetail }) {
    const installment = product.installmentAmount;

    if (installment === null) {
        return null;
    }

    const tiles = Array.from({ length: product.installmentsCount }, (_, index) => {
        const last = index === product.installmentsCount - 1 && product.lastInstallmentAmount !== null;

        return { n: index + 1, amount: last && product.lastInstallmentAmount !== null ? product.lastInstallmentAmount : installment };
    });

    return (
        <section aria-labelledby="plan-heading" className="bg-primary-soft flex flex-col gap-3 rounded-2xl p-[18px]">
            <h2 id="plan-heading" className="text-[15px] font-bold">
                Apártalo hoy y págalo en cuotas
            </h2>

            <ol aria-hidden="true" className="grid grid-cols-[repeat(auto-fill,minmax(84px,1fr))] gap-1.5">
                <Tile label="Inicial" amount={product.downPayment} hint="Hoy" first />
                {tiles.map((tile) => (
                    <Tile
                        key={tile.n}
                        label={`Cuota ${tile.n}`}
                        amount={tile.amount}
                        hint={`+${tile.n * product.frequencyDays} ${tile.n * product.frequencyDays === 1 ? 'día' : 'días'}`}
                    />
                ))}
            </ol>

            <dl className="text-ink-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                <dt>Inicial</dt>
                <dd>
                    <MoneyLabel money={product.downPayment} className="text-base-content font-semibold" />
                </dd>

                <dt>Cuotas</dt>
                <dd>
                    {product.installmentsCount} {product.installmentsCount === 1 ? 'cuota' : 'cuotas'} de{' '}
                    <MoneyLabel money={installment} className="text-base-content font-semibold" /> {frequencyLabel(product.frequencyDays)}
                </dd>

                {product.lastInstallmentAmount !== null ? (
                    <>
                        <dt>Última cuota</dt>
                        <dd>
                            <MoneyLabel money={product.lastInstallmentAmount} className="text-base-content font-semibold" />
                        </dd>
                    </>
                ) : null}
            </dl>
        </section>
    );
}
