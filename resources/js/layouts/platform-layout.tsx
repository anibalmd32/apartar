import { Brand } from '@/components/shell/brand';
import { RatePill } from '@/components/shell/rate-pill';
import { UserMenu } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { PlatformBell } from '@/features/platform/components/PlatformBell';
import { cilSearch, cilShieldAlt } from '@coreui/icons';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { PlatformNav, PlatformTabBar } from './platform/nav';

interface PlatformLayoutProps {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    back?: ReactNode;
    children: ReactNode;
}

export default function PlatformLayout({ title, description, actions, back, children }: PlatformLayoutProps) {
    const { props } = usePage<{
        platformOperator?: { name: string; email: string } | null;
    }>();
    const operator = props.platformOperator ?? null;

    const userMenu = (
        <UserMenu
            name={operator?.name ?? 'Operador'}
            detail={operator?.email ?? 'Plataforma Apartar'}
            onLogout={() => router.post('/plataforma/logout')}
        />
    );

    return (
        <div className="bg-base-200 min-h-screen">
            <Head title={`${title} · Plataforma`} />

            <aside className="border-line bg-base-100 fixed inset-y-0 left-0 z-30 hidden w-[260px] flex-col gap-5 overflow-y-auto border-r px-4 py-5 lg:flex">
                <Link href="/plataforma" className="self-start">
                    <Brand />
                </Link>

                <div className="border-line bg-base-100 flex items-center gap-2.5 rounded-xl border p-2.5">
                    <IconChip icon={cilShieldAlt} size={32} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-bold">Apartar · Plataforma</p>
                        <p className="text-ink-3 truncate text-xs">Operación</p>
                    </div>
                </div>

                <PlatformNav />
            </aside>

            <div className="flex min-h-screen flex-col lg:pl-[260px]">
                <header className="border-line bg-base-100 sticky top-0 z-20 hidden h-[68px] items-center gap-4 border-b px-8 lg:flex">
                    <form
                        role="search"
                        className="bg-surface-2 focus-within:outline-primary flex h-10 w-[360px] items-center gap-2.5 rounded-[10px] px-3 focus-within:outline-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            const term = new FormData(event.currentTarget).get('q');
                            router.get('/plataforma/organizaciones', typeof term === 'string' && term.trim() !== '' ? { q: term.trim() } : {});
                        }}
                    >
                        <Icon icon={cilSearch} className="text-ink-3" />
                        <input
                            name="q"
                            type="search"
                            aria-label="Buscar organización"
                            placeholder="Buscar organización…"
                            className="placeholder:text-ink-3 min-w-0 flex-1 bg-transparent text-sm outline-none"
                        />
                    </form>
                    <div className="flex-1" />
                    <RatePill />
                    <PlatformBell />
                    {userMenu}
                </header>

                <header className="bg-base-200 flex h-14 items-center gap-3 px-4 lg:hidden">
                    <IconChip icon={cilShieldAlt} size={34} />
                    <div className="min-w-0 flex-1">
                        <p className="text-ink-3 text-xs">Apartar</p>
                        <p className="text-h2 truncate">Plataforma</p>
                    </div>
                    <PlatformBell />
                    {userMenu}
                </header>

                <main className="flex-1 px-4 pt-2 pb-32 lg:px-8 lg:pt-7 lg:pb-12">
                    <div className="mx-auto flex w-full max-w-[1400px] flex-col gap-4 lg:gap-6">
                        <section className="flex flex-col gap-3.5 lg:flex-row lg:items-end lg:justify-between lg:gap-4">
                            <div className="flex min-w-0 flex-col gap-1.5">
                                {back}
                                <h1 className="text-h1 max-lg:text-[22px]">{title}</h1>
                                {description ? <div className="text-ink-2 text-sm">{description}</div> : null}
                            </div>
                            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
                        </section>

                        {children}
                    </div>
                </main>
            </div>

            <PlatformTabBar />
        </div>
    );
}
