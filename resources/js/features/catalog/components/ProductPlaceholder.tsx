import { cn } from '@/lib/utils';

const TONES = ['bg-primary-soft', 'bg-info-soft', 'bg-success-soft', 'bg-warning-soft', 'bg-error-soft', 'bg-neutral-soft'] as const;

function toneOf(name: string): string {
    let hash = 0;

    for (const char of name) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return TONES[hash % TONES.length] ?? TONES[0];
}

interface ProductPlaceholderProps {
    name: string;
    labelled?: boolean;
    className?: string;
}

export function ProductPlaceholder({ name, labelled = false, className }: ProductPlaceholderProps) {
    return (
        <div
            role={labelled ? 'img' : undefined}
            aria-label={labelled ? name : undefined}
            aria-hidden={labelled ? undefined : true}
            className={cn('text-ink-3 flex h-full w-full items-center justify-center text-5xl font-extrabold', toneOf(name), className)}
        >
            {name.trim().charAt(0).toUpperCase()}
        </div>
    );
}
