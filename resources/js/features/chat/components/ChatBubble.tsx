import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface ChatBubbleProps {
    mine: boolean;
    body: string;
    meta?: ReactNode;
    pending?: boolean;
    startsGroup?: boolean;
}

export function ChatBubble({ mine, body, meta = null, pending = false, startsGroup = true }: ChatBubbleProps) {
    return (
        <li className={cn('flex flex-col gap-1', mine ? 'items-end' : 'items-start', startsGroup && 'mt-2.5 first:mt-0')}>
            <div
                className={cn(
                    'max-w-[85%] rounded-2xl px-3.5 py-2.5 text-sm leading-[1.45] break-words whitespace-pre-wrap sm:max-w-[75%]',
                    mine ? 'bg-primary text-primary-content' : 'bg-surface-2',
                    pending && 'opacity-70',
                )}
            >
                {body}
            </div>
            {meta === null ? null : <div className="text-ink-3 text-[11px]">{meta}</div>}
        </li>
    );
}

export function DaySeparator({ label }: { label: string }) {
    return (
        <li className="bg-surface-2 text-ink-3 my-2 self-center rounded-full px-2.5 py-[3px] text-[11px] font-semibold first:mt-0" data-day-separator>
            {label}
        </li>
    );
}
