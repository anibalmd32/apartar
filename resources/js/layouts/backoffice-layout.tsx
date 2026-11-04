import { AttentionBell } from '@/components/shell/attention-bell';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { GlobalSearch } from '@/components/shell/global-search';
import { OrganizationSwitcher } from '@/components/shell/organization-switcher';
import { RatePill } from '@/components/shell/rate-pill';
import { ThemeToggle } from '@/components/shell/theme-toggle';
import { menuItemClass, UserMenu } from '@/components/shell/user-menu';
import { BackLink } from '@/components/ui/back-link';
import { DOCKED_BAR_PADDING } from '@/components/ui/form-action-bar';
import { Icon } from '@/components/ui/icon';
import { PAGE_ACTIONS_CLASS, PAGE_HEADER_CLASS, PAGE_TITLE_CLASS } from '@/components/ui/page-actions';
import { useChatNotifications } from '@/features/chat/hooks/useChatNotifications';
import { BoardBrand } from '@/features/marketing/board/BoardBrand';
import { PastDueBanner } from '@/features/plan/components/PastDueBanner';
import { UpgradePrompt } from '@/features/plan/components/UpgradePrompt';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { usePendingReceipts } from '@/features/receipts/hooks/useReceiptInbox';
import { type BackofficeSharedData } from '@/types/backoffice';
import { cilLockLocked, cilSettings, cilUser, cilX } from '@coreui/icons';
import { Dialog, DialogPanel, MenuItem } from '@headlessui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type ReactNode, useEffect, useState } from 'react';

import { MobileHeader } from '@/layouts/backoffice/mobile-header';
import { BackofficeNav } from '@/layouts/backoffice/nav';
import { PlanUsage } from '@/layouts/backoffice/plan-usage';
import { CHAT_HREF, RECEIPTS_HREF, ROLE_LABELS, SECTIONS, TABS } from '@/layouts/backoffice/sections';
import { SupportAccessBanner } from '@/layouts/backoffice/support-access-banner';
import { TabBar } from '@/layouts/backoffice/tab-bar';
import { bo } from '@/lib/surfaces';

interface BackofficeLayoutProps {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    back?: { href: string; label: string };
    badge?: ReactNode;
    immersive?: boolean;
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

function BackofficeFrame({ title, description, actions, back, badge, immersive = false, children }: BackofficeLayoutProps) {
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

    const logout = () => router.post(bo('/logout'));

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
                        <Link href={bo('/perfil')} className={menuItemClass}>
                            <Icon icon={cilUser} />
                            Mi perfil
                        </Link>
                    </MenuItem>
                    {capabilities.includes('settings') ? (
                        <MenuItem>
                            <Link href={bo('/ajustes')} className={menuItemClass}>
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

            <aside className="border-line bg-base-200 fixed inset-y-0 left-0 z-30 hidden w-[248px] flex-col gap-5 overflow-y-auto border-r-2 px-4 py-6 lg:flex">
                <Link href={bo('')} className="touch:min-h-11 self-start px-1" aria-label="Apartar, hoy">
                    <BoardBrand />
                </Link>

                {organizationCard}

                <BackofficeNav sections={SECTIONS} capabilities={capabilities} badges={badges} />

                <PlanUsage className="mt-auto" />
            </aside>

            <div className="flex min-h-screen flex-col lg:pl-[248px]">
                <header className="border-line bg-base-200 sticky top-0 z-20 hidden h-[68px] items-center gap-4 border-b-2 px-8 lg:flex">
                    <GlobalSearch />
                    <div className="flex-1" />
                    <RatePill />
                    <CurrencySwitch />
                    <ThemeToggle className="border-line rounded-[10px] border" />
                    <AttentionBell pendingReceipts={pendingReceipts} />
                    {userMenu}
                </header>

                {immersive ? null : (
                    <MobileHeader organizationName={organization?.name ?? 'Apartar'} pendingReceipts={pendingReceipts} userMenu={userMenu} />
                )}

                {suspended ? (
                    <div
                        role="alert"
                        className={`bg-error text-error-content flex flex-wrap items-center gap-3 px-4 py-3 lg:px-8 ${immersive ? 'max-lg:hidden' : ''}`}
                    >
                        <Icon icon={cilLockLocked} size={18} />
                        <p className="min-w-0 flex-1 text-[13px] font-semibold">
                            Tu suscripción está suspendida por falta de pago. Puedes cobrar y aprobar comprobantes, pero no crear cosas nuevas.
                        </p>
                        <Link className="btn btn-sm bg-base-100 text-base-content hover:bg-base-200 border-0" href={bo('/suscripcion')}>
                            Regularizar el pago
                        </Link>
                    </div>
                ) : null}

                <main
                    className={
                        immersive ? 'flex-1 lg:px-8 lg:pt-7 lg:pb-12' : `flex-1 px-4 pt-2 pb-32 lg:px-8 lg:pt-7 lg:pb-12 ${DOCKED_BAR_PADDING}`
                    }
                >
                    <div className="mx-auto flex w-full max-w-[1400px] flex-col gap-4 lg:gap-6">
                        <div className={immersive ? 'contents max-lg:hidden' : 'contents'}>
                            <PastDueBanner />

                            <SupportAccessBanner grants={teamMember?.supportAccess ?? []} />

                            <UpgradePrompt />
                        </div>

                        <section className={immersive ? `${PAGE_HEADER_CLASS} max-lg:hidden` : PAGE_HEADER_CLASS}>
                            <div className={PAGE_TITLE_CLASS}>
                                {back ? <BackLink href={back.href}>{back.label}</BackLink> : null}
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <h1 className="text-h1">{title}</h1>
                                    {badge}
                                </div>
                                {description ? <div className="text-ink-2 text-sm">{description}</div> : null}
                            </div>
                            {actions ? <div className={PAGE_ACTIONS_CLASS}>{actions}</div> : null}
                        </section>

                        {children}
                    </div>
                </main>
            </div>

            {immersive ? null : (
                <TabBar tabs={TABS} capabilities={capabilities} badges={badges} onMore={() => setMenuOpen(true)} moreOpen={menuOpen} />
            )}

            <Dialog open={menuOpen} onClose={() => setMenuOpen(false)} className="relative z-50 lg:hidden">
                <div className="bg-neutral/40 fixed inset-0" aria-hidden="true" />
                <DialogPanel className="bg-base-200 fixed inset-y-0 right-0 flex w-[min(320px,88vw)] flex-col gap-5 overflow-y-auto px-4 py-5">
                    <div className="flex items-center justify-between">
                        <BoardBrand />
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
                    <RatePill className="self-start" />
                    <BackofficeNav sections={SECTIONS} capabilities={capabilities} badges={badges} />
                    <PlanUsage className="mt-auto" />
                </DialogPanel>
            </Dialog>
        </div>
    );
}
