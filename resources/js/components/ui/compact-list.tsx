import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Children, type HTMLAttributes, type ReactNode } from 'react';

const STRETCH = 'outline-none after:absolute after:inset-0 after:rounded-[inherit] focus-visible:after:ring-2 focus-visible:after:ring-primary';

export function CompactList({ className, children, ...props }: HTMLAttributes<HTMLUListElement>) {
    return (
        <ul className={cn('bg-base-100 border-line divide-line flex flex-col divide-y overflow-hidden rounded-2xl border', className)} {...props}>
            {children}
        </ul>
    );
}

export interface CompactRowProps extends Omit<HTMLAttributes<HTMLLIElement>, 'title'> {
    title: ReactNode;
    subtitle?: ReactNode;
    meta?: ReactNode;
    amount?: ReactNode;
    status?: ReactNode;
    media?: ReactNode;
    action?: ReactNode;
    leading?: ReactNode;
    href?: string | null;
    external?: boolean;
    onActivate?: () => void;
    activateLabel?: string;
    selected?: boolean;
    muted?: boolean;
}

function filled(node: ReactNode): boolean {
    return Children.toArray(node).length > 0;
}

function Title({
    title,
    href,
    external,
    onActivate,
    activateLabel,
}: Pick<CompactRowProps, 'title' | 'href' | 'external' | 'onActivate' | 'activateLabel'>) {
    if (onActivate) {
        return (
            <button type="button" className={cn('text-left', STRETCH)} onClick={onActivate} aria-label={activateLabel}>
                {title}
            </button>
        );
    }

    if (href) {
        return external ? (
            <a href={href} className={STRETCH} aria-label={activateLabel}>
                {title}
            </a>
        ) : (
            <Link href={href} className={STRETCH} aria-label={activateLabel}>
                {title}
            </Link>
        );
    }

    return <>{title}</>;
}

export function CompactRow({
    title,
    subtitle,
    meta,
    amount,
    status,
    media,
    action,
    leading,
    href,
    external = false,
    onActivate,
    activateLabel,
    selected = false,
    muted = false,
    className,
    ...props
}: CompactRowProps) {
    const tappable = Boolean(href) || onActivate !== undefined;

    return (
        <li
            className={cn(
                'relative flex min-h-[4.5rem] items-center gap-3 px-4 py-3 [-webkit-touch-callout:none]',
                tappable && 'active:bg-surface-2 cursor-pointer',
                selected && 'bg-primary/5',
                className,
            )}
            data-selected={selected || undefined}
            {...props}
        >
            {leading ? <div className="relative z-10 flex shrink-0 items-center">{leading}</div> : null}
            {media ? <div className={cn('shrink-0', muted && 'opacity-60')}>{media}</div> : null}
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex items-start justify-between gap-3">
                    <p className={cn('min-w-0 truncate text-[15px] leading-snug font-semibold', muted && 'text-ink-2')}>
                        <Title title={title} href={href} external={external} onActivate={onActivate} activateLabel={activateLabel} />
                    </p>
                    {filled(amount) ? <p className="shrink-0 text-[15px] leading-snug font-bold whitespace-nowrap tabular-nums">{amount}</p> : null}
                </div>
                {filled(subtitle) ? <p className="text-ink-3 truncate text-[13px] leading-snug">{subtitle}</p> : null}
                {filled(status) || filled(meta) ? (
                    <div className="text-ink-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] leading-snug">
                        {status}
                        {meta}
                    </div>
                ) : null}
            </div>
            {filled(action) ? <div className="relative z-10 flex shrink-0 items-center gap-1">{action}</div> : null}
        </li>
    );
}
