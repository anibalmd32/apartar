import { cn } from '@/lib/utils';

export interface BarDatum {
    readonly label: string;
    readonly value: number;
    readonly title?: string;
}

export function BarChart({
    data,
    label,
    height = 160,
    className,
}: {
    data: readonly BarDatum[];
    label: string;
    height?: number;
    className?: string;
}) {
    const max = Math.max(1, ...data.map((datum) => datum.value));

    return (
        <figure className={cn('flex flex-col gap-2', className)}>
            <div aria-hidden="true" className="flex items-end gap-1.5" style={{ height }}>
                {data.map((datum, index) => (
                    <div key={datum.label} className="flex h-full min-w-0 flex-1 flex-col justify-end" title={datum.title ?? String(datum.value)}>
                        <div
                            className={cn('w-full rounded-t-md', index === data.length - 1 ? 'bg-primary' : 'bg-primary/30')}
                            style={{ height: `${Math.max(datum.value > 0 ? 4 : 2, (datum.value / max) * 100)}%` }}
                        />
                    </div>
                ))}
            </div>
            <div aria-hidden="true" className="flex gap-1.5">
                {data.map((datum, index) => (
                    <span
                        key={datum.label}
                        className={cn(
                            'min-w-0 flex-1 truncate text-center text-[11px]',
                            index === data.length - 1 ? 'text-base-content font-semibold' : 'text-ink-3',
                        )}
                    >
                        {datum.label}
                    </span>
                ))}
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
