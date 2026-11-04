import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Alert } from '@/components/ui/alert';
import { type ChipTone, IconChip } from '@/components/ui/icon-chip';
import { BoardBrand } from '@/features/marketing/board/BoardBrand';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface AccessLayoutProps {
    title: string;
    description?: ReactNode;
    status?: string | null;
    children: ReactNode;
    footer?: ReactNode;
    icon?: string[];
    iconTone?: ChipTone;
    centered?: boolean;
    header?: ReactNode;
    badge?: ReactNode;
}

export default function AccessLayout({
    title,
    description,
    status,
    children,
    footer,
    icon,
    iconTone = 'primary',
    centered = false,
    header,
    badge,
}: AccessLayoutProps) {
    return (
        <main className="bg-base-200 flex min-h-screen flex-col gap-6 px-4 pt-6 pb-10 sm:items-center sm:justify-center sm:gap-7 sm:py-12">
            <Head title={title} />

            <div className="flex w-full items-center justify-between gap-3 sm:max-w-[440px]">
                <a href={route('home')} className="touch:min-h-11 inline-flex items-center" aria-label="Apartar, ir al inicio">
                    <BoardBrand />
                </a>
                <ThemeToggle />
            </div>

            <div className="sm:border-line sm:bg-base-100 flex w-full flex-col gap-4 sm:max-w-[440px] sm:rounded-xl sm:border sm:p-9">
                {header}

                <header className={cn('flex flex-col gap-4', centered && 'items-center text-center')}>
                    {icon ? (
                        <IconChip icon={icon} tone={iconTone} size={centered ? 64 : 48} className={centered ? 'rounded-[19px]' : 'rounded-[14px]'} />
                    ) : null}
                    {badge}
                    <h1
                        className={cn(
                            'font-extrabold',
                            centered || header ? 'text-[22px]' : cn('tracking-heading', icon ? 'text-2xl' : 'text-[26px]'),
                        )}
                    >
                        {title}
                    </h1>
                    {description ? <p className="text-ink-2 text-sm leading-normal">{description}</p> : null}
                </header>

                {status ? <Alert tone="success">{status}</Alert> : null}

                {children}

                {footer ? <div className="text-ink-2 text-center text-sm">{footer}</div> : null}
            </div>
        </main>
    );
}
