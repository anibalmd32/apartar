import { cn } from '@/lib/utils';
import { cilArrowLeft } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { Icon } from './icon';

interface BackLinkProps {
    href: string;
    children: ReactNode;
    className?: string;
}

export function BackLink({ href, children, className }: BackLinkProps) {
    return (
        <Link
            href={href}
            className={cn(
                'text-ink-3 hover:text-base-content touch:min-h-11 inline-flex items-center gap-1.5 self-start text-[13px] font-semibold',
                className,
            )}
        >
            <Icon icon={cilArrowLeft} size={14} />
            {children}
        </Link>
    );
}
