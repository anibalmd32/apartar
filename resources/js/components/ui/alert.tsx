import { cn } from '@/lib/utils';
import { cilCheckCircle, cilClock, cilInfo, cilWarning } from '@coreui/icons';
import { type ReactNode } from 'react';
import { Icon } from './icon';

export type AlertTone = 'info' | 'success' | 'warning' | 'error';

const TONES: Record<AlertTone, string> = {
    info: 'alert-info',
    success: 'alert-success',
    warning: 'alert-warning',
    error: 'alert-error',
};

const ICONS: Record<AlertTone, string[]> = {
    info: cilInfo,
    success: cilCheckCircle,
    warning: cilClock,
    error: cilWarning,
};

interface AlertProps {
    tone?: AlertTone;
    title?: ReactNode;
    children?: ReactNode;
    className?: string;
}

export function Alert({ tone = 'info', title, children, className }: AlertProps) {
    return (
        <div role="alert" className={cn('alert', TONES[tone])}>
            <Icon icon={ICONS[tone]} size={20} />
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                {title ? <p className="text-sm font-bold">{title}</p> : null}
                {children ? <div className={cn(title && 'text-ink-2', className && 'flex', className)}>{children}</div> : null}
            </div>
        </div>
    );
}
