import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export type ColumnTone = 'blue' | 'red' | 'green' | 'ink';

const FIGURE: Record<ColumnTone, string> = {
    blue: 'text-primary',
    red: 'text-error',
    green: 'text-success',
    ink: 'text-base-content',
};

interface BoardColumnProps {
    id: string;
    label: string;
    figure: ReactNode;
    tone: ColumnTone;
    detail?: ReactNode;
    more?: { href: string; label: string };
    children?: ReactNode;
}

export function BoardColumn({ id, label, figure, tone, detail, more, children }: BoardColumnProps) {
    return (
        <section aria-labelledby={id} className="flex min-w-0 flex-col">
            <h2 id={id} className="font-hand text-ink-2 text-2xl leading-none">
                {label}
            </h2>
            <p className={cn('font-vinyl mt-2 text-[3.25rem] tabular-nums lg:text-[3.75rem]', FIGURE[tone])}>{figure}</p>
            {detail ? <p className="text-ink-2 mt-1 text-[0.9375rem]">{detail}</p> : null}
            {children ? <ul className="mt-4 flex flex-col">{children}</ul> : null}
            {more ? (
                <Link
                    href={more.href}
                    className="text-primary touch:min-h-11 mt-3 inline-flex items-center self-start text-[0.9375rem] font-bold hover:underline"
                >
                    {more.label}
                </Link>
            ) : null}
        </section>
    );
}

interface BoardRowProps {
    title: ReactNode;
    meta?: ReactNode;
    amount?: ReactNode;
    action?: ReactNode;
}

export function BoardRow({ title, meta, amount, action }: BoardRowProps) {
    return (
        <li className="pz-rule-dashed grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 py-2.5">
            <span className="min-w-0 truncate text-[0.9375rem] font-bold">{title}</span>
            <span className="row-span-2 flex items-center gap-3">
                {amount ? <span className="text-right text-[0.9375rem] font-bold whitespace-nowrap tabular-nums">{amount}</span> : null}
                {action}
            </span>
            {meta ? <span className="text-ink-3 min-w-0 truncate text-[0.8125rem]">{meta}</span> : null}
        </li>
    );
}

export function EmptyNote({ children }: { children: ReactNode }) {
    return <li className="font-hand text-ink-3 py-2.5 text-xl">{children}</li>;
}

export const ROW_ACTION = 'btn btn-sm touch:min-h-11 min-h-9 px-3 text-[0.8125rem]';
