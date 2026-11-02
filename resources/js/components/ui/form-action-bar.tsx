import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface FormActionBarProps {
    sticky: boolean;
    children: ReactNode;
    className?: string;
}

export function FormActionBar({ sticky, children, className }: FormActionBarProps) {
    return (
        <div
            data-form-action-bar
            data-sticky={sticky || undefined}
            className={cn(
                'flex gap-2 max-lg:flex-col max-lg:[&>.btn]:w-full',
                sticky &&
                    'max-lg:bg-base-100 max-lg:border-line max-lg:shadow-pop max-lg:sticky max-lg:bottom-[calc(var(--overlay-bottom)+var(--consent-height)+0.75rem)] max-lg:z-30 max-lg:rounded-2xl max-lg:border max-lg:p-2',
                className,
            )}
        >
            {children}
        </div>
    );
}
