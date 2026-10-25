import { type ReactNode } from 'react';
import { IconChip } from './icon-chip';

interface EmptyStateProps {
    icon: string[];
    title: string;
    children: ReactNode;
    action?: ReactNode;
}

export function EmptyState({ icon, title, children, action }: EmptyStateProps) {
    return (
        <div className="mx-auto flex max-w-sm flex-col items-center gap-3 py-2 text-center">
            <IconChip icon={icon} size={48} />
            <p className="text-base-content text-base font-bold">{title}</p>
            <div className="text-ink-2 text-sm leading-relaxed">{children}</div>
            {action ? <div className="mt-1 flex flex-wrap justify-center gap-2">{action}</div> : null}
        </div>
    );
}
