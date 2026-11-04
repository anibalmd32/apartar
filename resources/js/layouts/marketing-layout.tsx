import { BoardFrame } from '@/features/marketing/board/BoardFrame';
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
        <>
            <Head title={title} />

            <BoardFrame header={<SiteHeader />} footer={<SiteFooter />}>
                {children}
            </BoardFrame>

            <CookieConsent surface="site" />
        </>
    );
}
