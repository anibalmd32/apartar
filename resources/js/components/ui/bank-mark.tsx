import { cn } from '@/lib/utils';

interface BankMarkProps {
    bank: string | null | undefined;
    logoUrl?: string | null;
    className?: string;
    size?: 'sm' | 'md';
}

const SIZES = { sm: 'size-6 rounded-md p-[3px] text-[9px]', md: 'size-8 rounded-lg p-1 text-[11px]' } as const;

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter((word) => /^[\p{L}\d]/u.test(word) && !['de', 'del', 'la', 'el'].includes(word.toLowerCase()))
        .slice(0, 2)
        .map((word) => word.charAt(0).toUpperCase())
        .join('');
}

export function BankMark({ bank, logoUrl, className, size = 'sm' }: BankMarkProps) {
    if (!bank) {
        return null;
    }

    return (
        <span
            aria-hidden={logoUrl ? undefined : true}
            className={cn('bg-surface-2 text-ink-2 inline-flex shrink-0 items-center justify-center font-bold', SIZES[size], className)}
        >
            {logoUrl ? <img src={logoUrl} alt={bank} className="size-full object-contain" loading="lazy" /> : initials(bank)}
        </span>
    );
}
