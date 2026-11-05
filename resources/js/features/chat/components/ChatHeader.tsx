import { Avatar } from '@/components/shell/avatar';
import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilArrowLeft } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface ChatHeaderProps {
    name: string;
    backHref: string;
    backLabel: string;
    heading?: 'h1' | 'h2';
    backOnDesktop?: boolean;
    children?: ReactNode;
    subtitle?: ReactNode;
}

export function ChatHeader({ name, backHref, backLabel, heading: Heading = 'h2', backOnDesktop = false, subtitle, children }: ChatHeaderProps) {
    return (
        <header className="border-line bg-base-100 flex min-h-14 shrink-0 items-center gap-2 border-b py-1.5 pr-3 pl-1 lg:gap-3 lg:px-5 lg:py-3">
            <Link href={backHref} className={cn('btn btn-ghost btn-square size-11 shrink-0', !backOnDesktop && 'lg:hidden')} aria-label={backLabel}>
                <Icon icon={cilArrowLeft} size={20} />
            </Link>
            <Avatar name={name} size={36} tone="accent" />
            <div className="flex min-w-0 flex-1 flex-col">
                <Heading className="truncate text-[16px] font-bold">{name}</Heading>
                {subtitle}
            </div>
            {children}
            <ThemeToggle className="lg:hidden" />
        </header>
    );
}
