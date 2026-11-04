import { LiveRateStamp, RateChangedNotice } from '@/features/catalog/components/LiveRateStamp';
import { PlatformBadge } from '@/features/catalog/components/PlatformBadge';
import { ShopHeader } from '@/features/catalog/components/ShopHeader';
import { useLiveRate } from '@/features/catalog/hooks/useLiveRate';
import { CookieConsent, CookiePreferencesLink } from '@/features/telemetry/components/CookieConsent';
import { storeStyle } from '@/lib/store-brand';
import { cn } from '@/lib/utils';
import { type OrganizationBrand } from '@/types/backoffice';
import { type CurrencyPageProps } from '@/types/exchange';
import { Head, usePage } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type ReactNode, useState } from 'react';

interface CatalogLayoutProps {
    title: string;
    business: OrganizationBrand;
    showsPlatformBadge: boolean;
    width?: 'wide' | 'narrow';
    children: ReactNode;
}

export default function CatalogLayout(props: CatalogLayoutProps) {
    const [queryClient] = useState(() => new QueryClient());

    return (
        <QueryClientProvider client={queryClient}>
            <CatalogFrame {...props} />
        </QueryClientProvider>
    );
}

function CatalogFrame({ title, business, showsPlatformBadge, width = 'wide', children }: CatalogLayoutProps) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;
    const live = useLiveRate(currency.rate);

    return (
        <div
            className="vitrina bg-base-200 flex min-h-screen flex-col has-[[data-chat-dock]]:pb-[calc(var(--overlay-bottom)+var(--consent-height)+4.5rem)]"
            style={storeStyle(business.brandingEnabled ? business.brandColor : null)}
        >
            <Head title={title} />

            <ShopHeader business={business} rate={live.rate} />
            <RateChangedNotice live={live} />

            <main
                className={cn(
                    'mx-auto flex w-full flex-1 flex-col gap-6 px-4 pt-4 pb-6 lg:px-20 lg:pt-8 lg:pb-12',
                    width === 'wide' ? 'max-w-[1440px]' : 'max-w-[1120px]',
                )}
            >
                {children}
                <LiveRateStamp live={live} timezone={currency.timezone} className="text-center lg:text-left" />
            </main>

            <footer className="mx-auto flex w-full max-w-[1440px] flex-col gap-2 px-4 pt-6 pb-8 lg:flex-row lg:items-center lg:justify-between lg:px-20 lg:py-7">
                <p className="text-ink-3 text-xs">
                    Los pagos se hacen directo a {business.name}. Apartar no cobra ni recibe dinero de clientes.{' '}
                    <CookiePreferencesLink className="hover:text-base-content cursor-pointer underline underline-offset-2" />
                </p>
                {showsPlatformBadge ? <PlatformBadge /> : null}
            </footer>

            <CookieConsent />
        </div>
    );
}
