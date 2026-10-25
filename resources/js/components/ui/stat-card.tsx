import { cn } from '@/lib/utils';
import { cilArrowRight } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { Icon } from './icon';
import { type ChipTone, IconChip } from './icon-chip';

interface StatCardProps {
    label: string;
    value: ReactNode;
    icon?: string[];
    tone?: ChipTone;
    href?: string;
    children?: ReactNode;
    className?: string;
}

export function StatCard({ label, value, icon, tone = 'primary', href, children, className }: StatCardProps) {
    const body = (
        <>
            <span className="flex items-center gap-2.5">
                {icon ? <IconChip icon={icon} tone={tone} /> : null}
                <span className="text-ink-2 flex-1 text-[13px] leading-tight font-semibold">{label}</span>
                {href ? <Icon icon={cilArrowRight} size={14} className="text-ink-3 max-sm:hidden" /> : null}
            </span>
            <span className="text-figure">{value ?? '—'}</span>
            {children !== null && children !== undefined && children !== false ? (
                <span className="text-ink-3 flex flex-wrap items-center gap-1.5 text-xs">{children}</span>
            ) : null}
        </>
    );

    const classes = cn('bg-base-100 border-line shadow-card flex flex-col gap-3 rounded-2xl border p-[18px]', className);

    return href ? (
        <Link href={href} className={cn(classes, 'hover:border-line-strong transition-colors')}>
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}
