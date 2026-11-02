import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilWarning } from '@coreui/icons';
import type { ReactNode } from 'react';

interface FieldErrorProps {
    id?: string;
    className?: string;
    children: ReactNode;
}

export function FieldError({ id, className, children }: FieldErrorProps) {
    return (
        <p id={id} role="alert" className={cn('field-error text-error flex items-start gap-1.5 text-xs leading-snug font-medium', className)}>
            <Icon icon={cilWarning} size={13} className="mt-px" />
            <span className="min-w-0">{children}</span>
        </p>
    );
}
