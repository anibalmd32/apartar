import { SiteFooter } from '@/features/marketing/components/SiteFooter';
import { SiteHeader } from '@/features/marketing/components/SiteHeader';
import { CookieConsent } from '@/features/telemetry/components/CookieConsent';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface MarketingLayoutProps {
    title: string;
    children: ReactNode;
}

export default function MarketingLayout({ title, children }: MarketingLayoutProps) {
    return (
        <div className="bg-base-200 flex min-h-screen flex-col">
            <Head title={title} />

            <SiteHeader />

            <main className="flex-1">{children}</main>

            <SiteFooter />

            <CookieConsent />
        </div>
    );
}
