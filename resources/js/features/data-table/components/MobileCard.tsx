import { cn } from '@/lib/utils';
import { type MobileSlot } from '@/types/data-table';
import { Link } from '@inertiajs/react';
import { Fragment, type ReactNode } from 'react';

interface MobileCardProps {
    slots: Record<MobileSlot, ReactNode[]>;
    fallbackTitle: ReactNode;
    href: string | null;
    amountLabel?: string;
    expanded?: ReactNode;
}

const STRETCH = 'outline-none after:absolute after:inset-0 after:rounded-[inherit] focus-visible:after:ring-2 focus-visible:after:ring-primary';

function joined(nodes: ReactNode[]): ReactNode {
    return nodes.map((node, index) => (
        <Fragment key={index}>
            {index > 0 ? ' · ' : null}
            {node}
        </Fragment>
    ));
}

export function MobileCard({ slots, fallbackTitle, href, amountLabel, expanded }: MobileCardProps) {
    const title = slots.title.length > 0 ? slots.title : fallbackTitle;
    const hasBody = slots.status.length > 0 || slots.meta.length > 0;

    return (
        <li className="bg-base-100 border-line flex flex-col overflow-hidden rounded-2xl border" data-card>
            <div className={cn('relative flex flex-col gap-3 px-4 pt-4', href && 'active:bg-surface-2', !hasBody && 'pb-4')}>
                <div className="flex items-start gap-3">
                    {slots.media.length > 0 ? <div className="shrink-0">{slots.media}</div> : null}
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <p className="min-w-0 text-[15px] leading-snug font-semibold break-words">
                            {href ? (
                                <Link href={href} className={STRETCH}>
                                    {title}
                                </Link>
                            ) : (
                                title
                            )}
                        </p>
                        {slots.subtitle.length > 0 ? (
                            <p className="text-ink-2 line-clamp-2 text-[13px] leading-snug">{joined(slots.subtitle)}</p>
                        ) : null}
                    </div>
                    {slots.amount.length > 0 ? (
                        <p className="flex shrink-0 flex-col items-end leading-snug">
                            <span className="text-base font-bold whitespace-nowrap tabular-nums">{slots.amount}</span>
                            {amountLabel ? <span className="text-ink-3 text-[11px] font-medium">{amountLabel}</span> : null}
                        </p>
                    ) : null}
                </div>
                {hasBody ? (
                    <div className="text-ink-2 flex flex-wrap items-center gap-x-3 gap-y-2 pb-4 text-[13px] leading-snug [&>span:has([role=progressbar])]:basis-full">
                        {slots.status}
                        {slots.meta}
                    </div>
                ) : null}
            </div>
            {slots.action.length > 0 ? (
                <div className="border-line flex items-center justify-center gap-3 border-t px-4 py-2 [&>span]:contents" data-card-actions>
                    {slots.action}
                </div>
            ) : null}
            {expanded ? <div className="border-line border-t">{expanded}</div> : null}
        </li>
    );
}
