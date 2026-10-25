import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface MeterProps {
    label: ReactNode;
    value: ReactNode;
    percentage: number | null;
    warn?: boolean;
    className?: string;
    as?: 'div' | 'li';
}

export function Meter({ label, value, percentage, warn = false, className, as: Tag = 'div' }: MeterProps) {
    return (
        <Tag className={cn('flex flex-col gap-1.5', className)} data-near={warn || undefined}>
            <span className="flex items-baseline justify-between gap-3 text-[13px]">
                <span className="text-ink-2 first-letter:uppercase">{label}</span>
                <span className={cn('font-semibold whitespace-nowrap', warn && 'text-warning')}>
                    {value}
                    {warn ? <span className="sr-only"> · cerca del límite</span> : null}
                </span>
            </span>
            {percentage === null ? null : (
                <span className="bg-base-300 block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                    <span
                        className={cn('block h-full rounded-full', warn ? 'bg-warning' : 'bg-primary')}
                        style={{ width: `${Math.min(100, Math.max(0, percentage))}%` }}
                    />
                </span>
            )}
        </Tag>
    );
}
