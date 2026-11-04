import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilChevronBottom } from '@coreui/icons';
import { type ReactNode } from 'react';

interface ToolDisclosureProps {
    title: string;
    hint: string;
    children: ReactNode;
    className?: string;
}

export function ToolDisclosure({ title, hint, children, className }: ToolDisclosureProps) {
    return (
        <details className={cn('group border-line rounded-box border-2 border-dashed', className)}>
            <summary className="touch:min-h-11 flex min-h-12 cursor-pointer list-none items-center gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="text-[0.9375rem] font-bold">{title}</span>
                    <span className="text-ink-3 text-[0.8125rem]">{hint}</span>
                </span>
                <Icon icon={cilChevronBottom} size={14} className="text-ink-3 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="border-line border-t-2 border-dashed px-4 py-4">{children}</div>
        </details>
    );
}
