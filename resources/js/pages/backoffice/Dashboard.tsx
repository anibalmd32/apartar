import { Icon } from '@/components/ui/icon';
import { CollectColumn, MonthColumn, ReviewColumn } from '@/features/dashboard/board/TodayColumns';
import { DashboardWarnings } from '@/features/dashboard/components/DashboardWarnings';
import { HowItWorks, SetupChecklist } from '@/features/dashboard/components/SetupChecklist';
import { SuspensionCard } from '@/features/dashboard/components/SuspensionCard';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { bo } from '@/lib/surfaces';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type DashboardProps } from '@/types/dashboard';
import { type CurrencyPageProps } from '@/types/exchange';
import { cilPlus } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';

const rateFormat = new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function Dashboard({ metrics, links, pendingReceipts = null, collectToday = null, suspension = null }: DashboardProps) {
    const { teamMember, currency } = usePage<BackofficeSharedData & { currency?: CurrencyPageProps }>().props;
    const setup = metrics.setup;
    const starting = setup !== null && !(setup.paymentAccount && setup.product && setup.publishedCatalog);
    const timezone = currency?.timezone;
    const firstName = teamMember?.name.split(' ')[0] ?? '';
    const rate = metrics.rate?.rate ?? null;
    const capabilities = teamMember?.capabilities ?? [];

    return (
        <BackofficeLayout
            title={`${greeting(timezone)}${firstName ? `, ${firstName}` : ''}`}
            description={
                <span className="font-hand text-base-content text-xl">
                    {today(timezone)}
                    {rate !== null ? (
                        <>
                            {' · '}
                            <span className="whitespace-nowrap">
                                {metrics.rate?.sourceLabel ?? 'Tasa'} <span className="text-primary">Bs. {rateFormat.format(rate)}</span>
                            </span>
                        </>
                    ) : null}
                </span>
            }
            actions={
                capabilities.includes('products') ? (
                    <Link href={bo('/productos/crear')} className="btn btn-primary">
                        <Icon icon={cilPlus} />
                        Nuevo producto
                    </Link>
                ) : null
            }
        >
            <LimitBanner metric="active_layaways" />

            {suspension !== null ? <SuspensionCard suspension={suspension} invoicesUrl={links.invoices ?? null} /> : null}

            {starting ? (
                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-5">
                    <SetupChecklist setup={setup} />
                    <HowItWorks />
                </div>
            ) : null}

            <div className="divide-line grid grid-cols-1 divide-y-2 lg:grid-cols-3 lg:divide-x-2 lg:divide-y-0">
                {pendingReceipts !== null ? (
                    <div className="py-6 first:pt-0 lg:px-8 lg:py-0 lg:first:pl-0">
                        <ReviewColumn metrics={metrics} receipts={pendingReceipts} href={links.receipts} />
                    </div>
                ) : null}
                {collectToday !== null ? (
                    <div className="py-6 first:pt-0 lg:px-8 lg:py-0 lg:first:pl-0">
                        <CollectColumn
                            collect={collectToday}
                            rate={rate}
                            business={teamMember?.organization?.name ?? ''}
                            portalUrl={route('portal.access')}
                        />
                    </div>
                ) : null}
                <div className="py-6 first:pt-0 lg:px-8 lg:py-0 lg:first:pl-0 lg:last:pr-0">
                    <MonthColumn metrics={metrics} collect={collectToday} links={links} />
                </div>
            </div>

            <DashboardWarnings warnings={metrics.warnings} />
        </BackofficeLayout>
    );
}

function greeting(timezone: string | undefined): string {
    const hour = Number(new Intl.DateTimeFormat('es-VE', { hour: 'numeric', hourCycle: 'h23', timeZone: timezone }).format(new Date()));

    return hour < 12 ? 'Buenos días' : hour < 19 ? 'Buenas tardes' : 'Buenas noches';
}

function today(timezone: string | undefined): string {
    const text = new Intl.DateTimeFormat('es-VE', { weekday: 'long', day: 'numeric', month: 'long', timeZone: timezone }).format(new Date());

    return `Hoy, ${text}`;
}
