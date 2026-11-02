import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface ToggleFieldProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
    stacked?: boolean;
    className?: string;
}

export function ToggleField({ checked, onChange, children, stacked = false, className }: ToggleFieldProps) {
    return (
        <label
            className={cn(
                'text-ink-2 flex cursor-pointer items-center gap-2.5 text-[13px] font-medium',
                stacked && 'border-line min-h-11 justify-between border-t pt-3 text-sm',
                className,
            )}
        >
            <input
                type="checkbox"
                className={cn('toggle toggle-sm', stacked && 'order-last')}
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
            />
            {children}
        </label>
    );
}
