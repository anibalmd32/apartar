import { AttentionBell } from '@/components/shell/attention-bell';
import { Avatar } from '@/components/shell/avatar';
import { Brand } from '@/components/shell/brand';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { GlobalSearch } from '@/components/shell/global-search';
import { OrganizationSwitcher } from '@/components/shell/organization-switcher';
import { RatePill } from '@/components/shell/rate-pill';
import { menuItemClass, UserMenu } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { useChatNotifications } from '@/features/chat/hooks/useChatNotifications';
import { PastDueBanner } from '@/features/plan/components/PastDueBanner';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { usePendingReceipts } from '@/features/receipts/hooks/useReceiptInbox';
import { type BackofficeSharedData } from '@/types/backoffice';
import { cilArrowLeft, cilLockLocked, cilSettings, cilUser, cilX } from '@coreui/icons';
import { Dialog, DialogPanel, MenuItem } from '@headlessui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type ReactNode, useEffect, useState } from 'react';

import { BackofficeNav } from '@/layouts/backoffice/nav';
import { PlanUsage } from '@/layouts/backoffice/plan-usage';
import { CHAT_HREF, RECEIPTS_HREF, ROLE_LABELS, SECTIONS, TABS } from '@/layouts/backoffice/sections';
import { SupportAccessBanner } from '@/layouts/backoffice/support-access-banner';
import { TabBar } from '@/layouts/backoffice/tab-bar';

interface BackofficeLayoutProps {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    back?: { href: string; label: string };
    badge?: ReactNode;
    children: ReactNode;
}

export default function BackofficeLayout(props: BackofficeLayoutProps) {
    const [queryClient] = useState(() => new QueryClient());

    return (
        <QueryClientProvider client={queryClient}>
            <BackofficeFrame {...props} />
        </QueryClientProvider>
    );
}

const TEAM_CHAT_PROPS = ['teamMember'];

function BackofficeFrame({ title, description, actions, back, badge, children }: BackofficeLayoutProps) {
    const { teamMember } = usePage<BackofficeSharedData>().props;
    useChatNotifications({
        channels: teamMember?.chatChannel ? [teamMember.chatChannel] : [],
        me: 'team',
        reloadProps: TEAM_CHAT_PROPS,
        chatPath: CHAT_HREF,
    });
    const pendingReceipts = usePendingReceipts(teamMember?.pendingReceipts ?? null, teamMember?.organization?.id ?? null);
    const plan = usePlanLimits();
    const [menuOpen, setMenuOpen] = useState(false);

    const organization = teamMember?.organization;
    const suspended = organization?.status === 'suspended';
    const capabilities = teamMember?.capabilities ?? [];
    const orgDetail = [plan.state ? `Plan ${plan.state.planName}` : null, teamMember ? ROLE_LABELS[teamMember.role] : null]
        .filter(Boolean)
        .join(' · ');

    const badges = {
        ...(pendingReceipts === null ? {} : { [RECEIPTS_HREF]: pendingReceipts }),
        ...(teamMember?.unreadChats ? { [CHAT_HREF]: teamMember.unreadChats } : {}),
    };

    useEffect(() => router.on('navigate', () => setMenuOpen(false)), []);

    const logout = () => router.post('/backoffice/logout');

    const organizationCard = (
        <OrganizationSwitcher
            name={organization?.name ?? 'Apartar'}
            detail={orgDetail}
            organizations={teamMember?.organizations ?? []}
            foundUrl={teamMember?.foundOrganizationUrl ?? null}
        />
    );

    const userMenu = teamMember ? (
        <UserMenu
            name={teamMember.name}
            detail={teamMember.email}
            onLogout={logout}
            extra={
                <>
                    <MenuItem>
                        <Link href="/backoffice/perfil" className={menuItemClass}>
                            <Icon icon={cilUser} />
                            Mi perfil
                        </Link>
                    </MenuItem>
                    {capabilities.includes('settings') ? (
                        <MenuItem>
                            <Link href="/backoffice/ajustes" className={menuItemClass}>
                                <Icon icon={cilSettings} />
                                Ajustes del negocio
                            </Link>
                        </MenuItem>
                    ) : null}
                </>
            }
        />
    ) : null;

    return (
        <div className="bg-base-200 min-h-screen">
            <Head title={title} />

            <aside className="border-line bg-base-100 fixed inset-y-0 left-0 z-30 hidden w-[260px] flex-col gap-5 overflow-y-auto border-r px-4 py-5 lg:flex">
                <Link href="/backoffice" className="self-start">
                    <Brand />
                </Link>

                {organizationCard}

                <BackofficeNav sections={SECTIONS} capabilities={capabilities} badges={badges} />

                <PlanUsage className="mt-auto" />
            </aside>

            <div className="flex min-h-screen flex-col lg:pl-[260px]">
                <header className="border-line bg-base-100 sticky top-0 z-20 hidden h-[68px] items-center gap-4 border-b px-8 lg:flex">
                    <GlobalSearch />
                    <div className="flex-1" />
                    <RatePill />
                    <CurrencySwitch />
                    <AttentionBell pendingReceipts={pendingReceipts} />
                    {userMenu}
                </header>

                <header className="bg-base-200 flex h-14 items-center gap-3 px-4 lg:hidden">
                    <Avatar name={organization?.name ?? 'Apartar'} size={34} tone="accent" />
                    <div className="min-w-0 flex-1">
                        {plan.state ? <p className="text-ink-3 text-xs">Plan {plan.state.planName}</p> : null}
                        <p className="text-h2 truncate">{organization?.name ?? 'Apartar'}</p>
                    </div>
                    <CurrencySwitch />
                    {userMenu}
                </header>

                {suspended ? (
                    <div role="alert" className="bg-error text-error-content flex flex-wrap items-center gap-3 px-4 py-3 lg:px-8">
                        <Icon icon={cilLockLocked} size={18} />
                        <p className="min-w-0 flex-1 text-[13px] font-semibold">
                            Tu suscripción está suspendida por falta de pago. Puedes cobrar y aprobar comprobantes, pero no crear cosas nuevas.
                        </p>
                        <Link className="btn btn-sm bg-base-100 text-base-content hover:bg-base-200 border-0" href="/backoffice/suscripcion">
                            Regularizar el pago
                        </Link>
                    </div>
                ) : null}

                <main className="flex-1 px-4 pt-2 pb-32 lg:px-8 lg:pt-7 lg:pb-12">
                    <div className="mx-auto flex w-full max-w-[1400px] flex-col gap-4 lg:gap-6">
                        <PastDueBanner />

                        <SupportAccessBanner grants={teamMember?.supportAccess ?? []} />

                        <UpgradePrompt />

                        <section className="flex flex-col gap-3.5 lg:flex-row lg:items-end lg:justify-between lg:gap-4">
                            <div className="flex min-w-0 flex-col gap-1.5">
                                {back ? (
                                    <Link
                                        href={back.href}
                                        className="text-ink-3 hover:text-base-content flex items-center gap-1.5 self-start text-[13px] font-semibold"
                                    >
                                        <Icon icon={cilArrowLeft} size={14} />
                                        {back.label}
                                    </Link>
                                ) : null}
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <h1 className="text-h1 max-lg:text-[22px]">{title}</h1>
                                    {badge}
                                </div>
                                {description ? <div className="text-ink-2 text-sm">{description}</div> : null}
                            </div>
                            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
                        </section>

                        {children}
                    </div>
                </main>
            </div>

            <TabBar tabs={TABS} capabilities={capabilities} badges={badges} onMore={() => setMenuOpen(true)} moreOpen={menuOpen} />

            <Dialog open={menuOpen} onClose={() => setMenuOpen(false)} className="relative z-50 lg:hidden">
                <div className="bg-neutral/40 fixed inset-0" aria-hidden="true" />
                <DialogPanel className="bg-base-100 fixed inset-y-0 right-0 flex w-[min(320px,88vw)] flex-col gap-5 overflow-y-auto px-4 py-5">
                    <div className="flex items-center justify-between">
                        <Brand />
                        <button
                            type="button"
                            className="btn btn-ghost btn-sm btn-square"
                            onClick={() => setMenuOpen(false)}
                            aria-label="Cerrar el menú"
                        >
                            <Icon icon={cilX} size={18} />
                        </button>
                    </div>
                    {organizationCard}
                    <BackofficeNav sections={SECTIONS} capabilities={capabilities} badges={badges} />
                    <PlanUsage className="mt-auto" />
                </DialogPanel>
            </Dialog>
        </div>
    );
}
