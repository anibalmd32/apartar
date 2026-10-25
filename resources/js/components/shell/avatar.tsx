import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

export type AvatarTone = 'accent' | 'primary' | 'neutral';

const TONES: Record<AvatarTone, string> = {
    accent: 'bg-accent/13 text-accent dark:bg-accent/28 dark:text-white',
    primary: 'bg-primary/13 text-primary dark:bg-primary/28 dark:text-white',
    neutral: 'bg-surface-3 text-ink-2',
};

interface AvatarProps {
    name: string;
    size?: number;
    tone?: AvatarTone;
    className?: string;
}

export function Avatar({ name, size = 36, tone = 'primary', className }: AvatarProps) {
    const getInitials = useInitials();

    return (
        <span
            aria-hidden="true"
            className={cn('inline-flex shrink-0 items-center justify-center rounded-full font-bold', TONES[tone], className)}
            style={{ width: size, height: size, fontSize: Math.round(size * 0.38) }}
        >
            {getInitials(name)}
        </span>
    );
}
