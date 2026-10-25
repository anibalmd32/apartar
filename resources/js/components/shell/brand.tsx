import { cn } from '@/lib/utils';

interface BrandProps {
    compact?: boolean;
    className?: string;
}

export function BrandMark({ className }: { className?: string }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'bg-primary text-primary-content inline-flex size-8 shrink-0 items-center justify-center rounded-[9px] text-xl leading-none font-extrabold',
                className,
            )}
        >
            a
        </span>
    );
}

export function Brand({ compact = false, className }: BrandProps) {
    return (
        <span className={cn('inline-flex items-center gap-2.5', className)}>
            <BrandMark />
            {compact ? <span className="sr-only">Apartar</span> : <span className="text-[19px] font-extrabold tracking-[-0.6px]">apartar</span>}
        </span>
    );
}
