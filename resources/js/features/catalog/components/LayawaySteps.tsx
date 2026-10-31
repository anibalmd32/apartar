import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilCheckAlt } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface LayawayStepsProps {
    current: 2 | 3;
    productUrl?: string;
    requiresDownPayment?: boolean;
}

const STEPS = ['Producto', 'Tus datos', 'Paga la inicial'] as const;

const STEPS_WITHOUT_DOWN_PAYMENT = ['Producto', 'Tus datos', 'Paga tus cuotas'] as const;

export function LayawaySteps({ current, productUrl, requiresDownPayment = true }: LayawayStepsProps) {
    return (
        <ol
            aria-label="Pasos del apartado"
            className="mx-auto flex max-w-full items-center justify-center gap-2.5 overflow-x-auto overflow-y-hidden text-sm"
        >
            {(requiresDownPayment ? STEPS : STEPS_WITHOUT_DOWN_PAYMENT).map((label, index) => {
                const n = index + 1;
                const done = n < current;
                const active = n === current;
                const content = (
                    <>
                        <span
                            className={cn(
                                'inline-flex size-[26px] shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                done && 'bg-success text-success-content',
                                active && 'bg-primary text-primary-content',
                                !done && !active && 'bg-surface-2 text-ink-3',
                            )}
                        >
                            {done ? <Icon icon={cilCheckAlt} size={12} /> : n}
                        </span>
                        <span className={cn('whitespace-nowrap', active ? 'font-bold' : 'text-ink-2 max-sm:sr-only')}>{label}</span>
                    </>
                );

                return (
                    <li key={label} aria-current={active ? 'step' : undefined} className="flex shrink-0 items-center gap-2.5">
                        {index > 0 ? <span aria-hidden="true" className="bg-line-strong h-px w-6 lg:w-10" /> : null}
                        {n === 1 && productUrl !== undefined ? (
                            <Link href={productUrl} className="flex items-center gap-2.5 hover:underline">
                                {content}
                            </Link>
                        ) : (
                            <span className="flex items-center gap-2.5">{content}</span>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
