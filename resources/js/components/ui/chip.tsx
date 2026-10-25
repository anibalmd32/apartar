import { cn } from '@/lib/utils';
import { type ButtonHTMLAttributes, type ReactNode } from 'react';

interface ChipProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
    active: boolean;
    children: ReactNode;
    count?: number | null;
}

export function Chip({ active, children, count, className, type = 'button', ...props }: ChipProps) {
    return (
        <button
            type={type}
            aria-pressed={active}
            className={cn(
                'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold whitespace-nowrap transition-colors',
                active ? 'bg-neutral text-neutral-content border-neutral' : 'bg-base-100 border-line-strong text-ink-2 hover:text-base-content',
                className,
            )}
            {...props}
        >
            {children}
            {count !== undefined && count !== null ? <span className={cn('text-xs', active ? 'opacity-80' : 'text-ink-3')}>{count}</span> : null}
        </button>
    );
}
