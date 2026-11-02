import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export const DOCKED_BAR_PADDING = 'max-lg:has-[[data-form-action-bar][data-sticky]]:pb-[calc(var(--overlay-bottom)+var(--consent-height))]';

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
                    'max-lg:bg-base-100 max-lg:border-line max-lg:sticky max-lg:bottom-[calc(var(--overlay-bottom)+var(--consent-height))] max-lg:z-30 max-lg:border-t max-lg:py-3',
                className,
            )}
        >
            {children}
        </div>
    );
}
