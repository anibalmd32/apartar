import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export type BadgeTone = 'neutral' | 'primary' | 'info' | 'success' | 'warning' | 'error';

const TONES: Record<BadgeTone, string> = {
    neutral: 'badge-neutral',
    primary: 'badge-primary',
    info: 'badge-info',
    success: 'badge-success',
    warning: 'badge-warning',
    error: 'badge-error',
};

interface BadgeProps {
    tone?: BadgeTone;
    outline?: boolean;
    count?: boolean;
    children: ReactNode;
    className?: string;
}

export function Badge({ tone = 'neutral', outline = false, count = false, children, className }: BadgeProps) {
    return <span className={cn('badge badge-sm', TONES[tone], outline && 'badge-outline', count && 'badge-count', className)}>{children}</span>;
}
