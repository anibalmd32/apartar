import { cn } from '@/lib/utils';

interface BankMarkProps {
    bank: string | null | undefined;
    logoUrl?: string | null;
    className?: string;
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter((word) => /^[\p{L}\d]/u.test(word) && !['de', 'del', 'la', 'el'].includes(word.toLowerCase()))
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
}

export function BankMark({ bank, logoUrl, className }: BankMarkProps) {
    if (!bank) {
        return null;
    }

    return logoUrl ? (
        <img src={logoUrl} alt={bank} className={cn('h-5 w-auto max-w-[96px] object-contain object-left', className)} loading="lazy" />
    ) : (
        <span
            aria-hidden="true"
            className={cn(
                'bg-surface-2 text-ink-2 inline-flex h-5 min-w-5 items-center justify-center rounded px-1 text-[10px] font-bold',
                className,
            )}
        >
            {initials(bank)}
        </span>
    );
}
