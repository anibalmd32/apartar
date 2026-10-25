import { cn } from '@/lib/utils';
import { type ElementType, type ReactNode } from 'react';

interface SectionCardProps {
    title?: ReactNode;
    subtitle?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    flush?: boolean;
    as?: ElementType;
    className?: string;
    'aria-label'?: string;
}

export function SectionCard({ title, subtitle, action, children, flush = false, as: Tag = 'section', className, ...rest }: SectionCardProps) {
    const label = rest['aria-label'] ?? (typeof title === 'string' ? title : undefined);

    return (
        <Tag
            aria-label={Tag === 'section' ? label : undefined}
            className={cn('bg-base-100 border-line shadow-card flex flex-col rounded-2xl border', !flush && 'gap-3.5 p-5', className)}
        >
            {title || action ? (
                <div className={cn('flex flex-wrap items-start justify-between gap-x-3 gap-y-1', flush && 'px-5 pt-5 pb-3.5')}>
                    <div className="flex min-w-0 flex-col gap-0.5">
                        {title ? <h2 className="text-base leading-tight font-bold tracking-[-0.2px]">{title}</h2> : null}
                        {subtitle ? <p className="text-ink-3 text-[13px]">{subtitle}</p> : null}
                    </div>
                    {action ? <div className="text-primary-ink shrink-0 text-[13px] font-semibold">{action}</div> : null}
                </div>
            ) : null}
            {children}
        </Tag>
    );
}
