import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCheckAlt } from '@coreui/icons';
import { useState } from 'react';
import {
    EXAMPLE_PRICE_CENTS,
    formatBs,
    formatUsd,
    INSTALLMENT_CHOICES,
    type InstallmentChoice,
    layawaySum,
    type LayawaySum,
    shortDay,
} from './board-math';
import { MarkerCircle } from './MarkerCircle';

interface LayawaySumBoardProps {
    today: string;
    rate: string | null;
    className?: string;
}

function SumRows({ sum, rate }: { sum: LayawaySum; rate: string | null }) {
    return (
        <ol className="flex flex-col">
            {sum.rows.map((row, index) => (
                <li
                    key={row.label}
                    style={{ animationDelay: `${index * 70}ms` }}
                    className="pz-write pz-rule-dashed font-hand flex items-baseline justify-between gap-4 py-1.5 text-[1.375rem] leading-tight sm:text-[1.625rem]"
                >
                    <span className={cn(row.paid && 'text-pz-ink-3 decoration-pz-red line-through decoration-[3px]')}>
                        {row.label}
                        {row.due === null ? null : <span className="text-pz-ink-3"> · {shortDay(row.due)}</span>}
                    </span>
                    <span className={cn('shrink-0 tabular-nums', row.paid && 'text-pz-ink-3')}>
                        {formatUsd(row.cents)}
                        {row.paid ? <Icon icon={cilCheckAlt} size={18} label="pagada" className="text-pz-green ml-1.5 inline align-[-2px]" /> : null}
                    </span>
                </li>
            ))}
            {rate === null ? null : <li className="sr-only">Equivale a {formatBs(sum.remainingCents, rate)} a la tasa de hoy.</li>}
        </ol>
    );
}

export function LayawaySumBoard({ today, rate, className }: LayawaySumBoardProps) {
    const [installments, setInstallments] = useState<InstallmentChoice>(3);
    const [previous, setPrevious] = useState<InstallmentChoice | null>(null);
    const sum = layawaySum(installments, today);

    const choose = (choice: InstallmentChoice) => {
        if (choice === installments) {
            return;
        }

        setPrevious(installments);
        setInstallments(choice);
    };

    return (
        <figure className={cn('flex flex-col gap-3', className)} aria-label="Ejemplo de un apartado">
            <figcaption className="flex items-baseline justify-between gap-3">
                <span className="font-hand text-[1.625rem] leading-none sm:text-[1.875rem]">Vestido de lino · talla M</span>
                <span className="font-hand text-pz-ink-3 -rotate-3 text-lg">ejemplo</span>
            </figcaption>

            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <span className="font-vinyl text-[3.5rem] sm:text-[4.5rem]">{formatUsd(EXAMPLE_PRICE_CENTS)}</span>
                {rate === null ? null : (
                    <span className="font-hand text-pz-blue text-[1.375rem] sm:text-2xl">= {formatBs(EXAMPLE_PRICE_CENTS, rate)} hoy</span>
                )}
            </div>

            <div className="relative">
                {previous === null ? null : (
                    <div key={`ghost-${previous}-${installments}`} aria-hidden="true" className="pz-erase pointer-events-none absolute inset-0">
                        <SumRows sum={layawaySum(previous, today)} rate={null} />
                    </div>
                )}
                <div key={installments} aria-live="polite">
                    <SumRows sum={sum} rate={rate} />
                </div>
            </div>

            <div
                key={`resta-${installments}`}
                className="font-hand text-pz-red flex items-baseline justify-between pt-2 text-[1.75rem] sm:text-[2.125rem]"
            >
                <span>Resta</span>
                <MarkerCircle className="tabular-nums">{formatUsd(sum.remainingCents)}</MarkerCircle>
            </div>

            <fieldset className="mt-3 flex flex-wrap items-center gap-2">
                <legend className="sr-only">Número de cuotas del ejemplo</legend>
                {INSTALLMENT_CHOICES.map((choice) => (
                    <button
                        key={choice}
                        type="button"
                        aria-pressed={choice === installments}
                        onClick={() => choose(choice)}
                        className={cn(
                            'touch:min-h-11 min-h-10 cursor-pointer rounded-full px-4 text-[0.9375rem] font-bold transition-colors',
                            choice === installments
                                ? 'bg-pz-ink text-pz-board'
                                : 'text-pz-ink hover:bg-pz-ghost-ink shadow-[inset_0_0_0_2px_var(--pz-ink)]',
                        )}
                    >
                        {choice} cuotas
                    </button>
                ))}
            </fieldset>
        </figure>
    );
}
