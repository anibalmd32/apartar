import { Brand, BrandMark } from '@/components/shell/brand';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { RatePill } from '@/components/shell/rate-pill';
import { UserMenu } from '@/components/shell/user-menu';
import { PortalNav, portalNavItems, PortalTabBar } from '@/features/account/components/PortalNav';
import { useAccountSession } from '@/features/account/hooks/useAccountSession';
import { CookieConsent, CookiePreferencesLink } from '@/features/telemetry/components/CookieConsent';
import { Head, Link, router } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type ReactNode, useState } from 'react';

interface PortalLayoutProps {
    title: string;
    businessName: string;
    customerName: string;
    children: ReactNode;
}

export default function PortalLayout(props: PortalLayoutProps) {
    const [queryClient] = useState(() => new QueryClient());

    return (
        <QueryClientProvider client={queryClient}>
            <PortalFrame {...props} />
        </QueryClientProvider>
    );
}

function PortalFrame({ title, businessName, customerName, children }: PortalLayoutProps) {
    const { isAccount, session } = useAccountSession();
    const items = portalNavItems(isAccount, session?.unreadChats ?? 0);

    const userMenu = <UserMenu name={customerName} detail={businessName} onLogout={() => router.post(route('portal.logout'))} />;

    return (
        <div className="bg-base-200 flex min-h-screen flex-col">
            <Head title={title} />

            <header className="border-line bg-base-100 sticky top-0 z-20 hidden h-[68px] items-center gap-7 border-b px-8 lg:flex xl:px-20">
                <Link href={route('portal.dashboard')} className="flex items-center gap-2">
                    <Brand />
                    <span className="bg-surface-2 text-ink-2 max-w-48 truncate rounded-full px-2.5 py-[3px] text-xs font-semibold">
                        {isAccount ? 'Mi cuenta' : businessName}
                    </span>
                </Link>

                <PortalNav items={items} />

                <div className="flex-1" />
                {isAccount ? null : <RatePill className="max-xl:hidden" />}
                <CurrencySwitch />
                <div className="flex items-center gap-2">
                    {userMenu}
                    <span className="max-w-40 truncate text-sm font-semibold">{customerName}</span>
                </div>
            </header>

            <header className="bg-base-200 flex h-14 items-center gap-3 px-4 lg:hidden">
                <Link href={route('portal.dashboard')} aria-label="Ir al inicio de tu cuenta">
                    <BrandMark className="size-[30px] rounded-lg text-[19px]" />
                </Link>
                <p className="min-w-0 flex-1 truncate text-[17px] font-bold">{businessName}</p>
                <CurrencySwitch />
                {userMenu}
            </header>

            <main className="mx-auto flex w-full max-w-[1184px] flex-1 flex-col gap-4 px-4 pt-2 pb-8 lg:gap-6 lg:px-8 lg:pt-8 lg:pb-14">
                {children}
            </main>

            <footer className="flex justify-center pt-2 pb-28 text-sm lg:pb-6">
                <CookiePreferencesLink className="link link-hover text-ink-3" />
            </footer>

            <PortalTabBar items={items} />

            <CookieConsent />
        </div>
    );
}
