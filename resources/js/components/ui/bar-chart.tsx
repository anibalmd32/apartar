import { cn } from '@/lib/utils';

export interface BarDatum {
    readonly label: string;
    readonly value: number;
    readonly title?: string;
}

const TICKS = 6;
const MOBILE_ALL_UP_TO = 6;
const DESKTOP_ALL_UP_TO = 12;

function tickStep(length: number, allUpTo: number): number {
    return length > allUpTo ? Math.ceil(length / TICKS) : 1;
}

function showsTick(index: number, last: number, step: number): boolean {
    return index === last || (index % step === 0 && last - index >= step);
}

export function tickVisibility(index: number, length: number): 'all' | 'desktop' | 'none' {
    const last = length - 1;

    if (showsTick(index, last, tickStep(length, MOBILE_ALL_UP_TO))) {
        return 'all';
    }

    return showsTick(index, last, tickStep(length, DESKTOP_ALL_UP_TO)) ? 'desktop' : 'none';
}

export function BarChart({ data, label, height, className }: { data: readonly BarDatum[]; label: string; height?: number; className?: string }) {
    const max = Math.max(1, ...data.map((datum) => datum.value));

    return (
        <figure className={cn('flex flex-col gap-2', className)}>
            <div
                aria-hidden="true"
                className={cn('flex items-end gap-1.5', height === undefined && 'h-[120px] lg:h-[160px]')}
                style={height ? { height } : undefined}
            >
                {data.map((datum, index) => (
                    <div key={datum.label} className="flex h-full min-w-0 flex-1 flex-col justify-end" title={datum.title ?? String(datum.value)}>
                        <div
                            className={cn('w-full rounded-t-md', index === data.length - 1 ? 'bg-primary' : 'bg-primary/30')}
                            style={{ height: `${Math.max(datum.value > 0 ? 4 : 2, (datum.value / max) * 100)}%` }}
                        />
                    </div>
                ))}
            </div>
            <div aria-hidden="true" className="flex gap-1.5" data-chart-ticks>
                {data.map((datum, index) => {
                    const visibility = tickVisibility(index, data.length);

                    return (
                        <span
                            key={datum.label}
                            className={cn(
                                'flex min-w-0 flex-1 justify-center text-[11px] whitespace-nowrap',
                                index === data.length - 1 ? 'text-base-content font-semibold' : 'text-ink-3',
                                visibility === 'desktop' && 'max-lg:invisible',
                                visibility === 'none' && 'invisible',
                            )}
                        >
                            <span>{datum.label}</span>
                        </span>
                    );
                })}
            </div>
            <figcaption className="sr-only">
                {label}
                <ul>
                    {data.map((datum) => (
                        <li key={datum.label}>
                            {datum.label}: {datum.title ?? datum.value}
                        </li>
                    ))}
                </ul>
            </figcaption>
        </figure>
    );
}
