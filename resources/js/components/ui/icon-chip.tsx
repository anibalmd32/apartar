import { cn } from '@/lib/utils';
import { Icon } from './icon';

export type ChipTone = 'primary' | 'info' | 'success' | 'warning' | 'error' | 'neutral';

const TONES: Record<ChipTone, string> = {
    primary: 'bg-primary-soft text-primary-ink',
    info: 'bg-info-soft text-info',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    error: 'bg-error-soft text-error',
    neutral: 'bg-neutral-soft text-ink-2',
};

interface IconChipProps {
    icon: string[];
    tone?: ChipTone;
    size?: number;
    className?: string;
}

export function IconChip({ icon, tone = 'primary', size = 32, className }: IconChipProps) {
    return (
        <span
            aria-hidden="true"
            className={cn('inline-flex shrink-0 items-center justify-center rounded-[10px]', TONES[tone], className)}
            style={{ width: size, height: size }}
        >
            <Icon icon={icon} size={Math.round(size * 0.5)} />
        </span>
    );
}
