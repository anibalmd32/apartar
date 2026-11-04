import { Avatar } from '@/components/shell/avatar';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { RatePill } from '@/components/shell/rate-pill';
import { UserMenu } from '@/components/shell/user-menu';
import { PortalNav, portalNavItems, PortalTabBar } from '@/features/account/components/PortalNav';
import { useAccountSession } from '@/features/account/hooks/useAccountSession';
import { useChatNotifications } from '@/features/chat/hooks/useChatNotifications';
import { CookieConsent, CookiePreferencesLink } from '@/features/telemetry/components/CookieConsent';
import { storeStyle } from '@/lib/store-brand';
import { cn } from '@/lib/utils';
import { type OrganizationBrand } from '@/types/backoffice';
import { Head, Link, router } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type ReactNode, useState } from 'react';

interface PortalLayoutProps {
    title: string;
    businessName: string;
    customerName: string;
    immersive?: boolean;
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

const PORTAL_CHAT_PROPS = ['auth'];

function BusinessMark({ business, name }: { business: OrganizationBrand | null; name: string }) {
    const logo = business?.brandingEnabled ? business.logoUrl : null;

    return logo ? (
        <img src={logo} alt="" className="border-line size-9 shrink-0 rounded-lg border object-contain" />
    ) : (
        <Avatar name={name} tone="neutral" className="size-9! shrink-0 rounded-lg! text-[13px]!" />
    );
}

const NO_CHANNELS: readonly string[] = [];

function PortalFrame({ title, businessName, customerName, immersive = false, children }: PortalLayoutProps) {
    const { isAccount, session } = useAccountSession();
    useChatNotifications({
        channels: session?.chatChannels ?? NO_CHANNELS,
        me: 'customer',
        reloadProps: PORTAL_CHAT_PROPS,
        chatPath: '/mi-cuenta/chat',
    });
    const items = portalNavItems(isAccount, session?.unreadChats ?? 0);

    const business = isAccount ? null : (session?.store ?? null);

    const userMenu = <UserMenu name={customerName} detail={businessName} onLogout={() => router.post(route('portal.logout'))} />;

    return (
        <div className="vitrina bg-base-200 flex min-h-screen flex-col" style={storeStyle(business?.brandingEnabled ? business.brandColor : null)}>
            <Head title={title} />

            <header className="border-line bg-base-100 sticky top-0 z-20 hidden h-[68px] items-center gap-7 border-b px-8 lg:flex xl:px-20">
                <Link href={route('portal.dashboard')} className="flex min-w-0 items-center gap-2.5">
                    <BusinessMark business={business} name={isAccount ? 'Mi cuenta' : businessName} />
                    <span className="max-w-56 truncate text-[1.0625rem] font-extrabold [font-stretch:90%]">
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

            <header className={cn('bg-base-200 flex h-14 items-center gap-3 px-4 lg:hidden', immersive && 'hidden')}>
                <Link
                    href={route('portal.dashboard')}
                    aria-label="Ir al inicio de tu cuenta"
                    className="hit-area flex min-w-0 flex-1 items-center gap-2.5"
                >
                    <BusinessMark business={business} name={businessName} />
                    <span className="min-w-0 truncate text-[1.0625rem] font-extrabold [font-stretch:90%]">{businessName}</span>
                </Link>
                <CurrencySwitch />
                {userMenu}
            </header>

            <main
                className={cn(
                    'mx-auto flex w-full max-w-[1184px] flex-1 flex-col gap-4 lg:gap-6 lg:px-8 lg:pt-8 lg:pb-14',
                    !immersive && 'px-4 pt-2 pb-8',
                )}
            >
                {children}
            </main>

            <footer className={cn('flex justify-center pt-2 pb-28 text-sm lg:pb-6', immersive && 'max-lg:hidden')}>
                <CookiePreferencesLink className="link link-hover text-ink-3" />
            </footer>

            {immersive ? null : <PortalTabBar items={items} />}

            <CookieConsent />
        </div>
    );
}
