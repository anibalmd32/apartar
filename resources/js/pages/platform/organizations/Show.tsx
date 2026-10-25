import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { SectionCard } from '@/components/ui/section-card';
import { InvoiceList } from '@/features/platform/components/InvoiceList';
import { OrganizationStatusBadge, PlanPill, SubscriptionStatusBadge } from '@/features/platform/components/labels';
import { OrganizationProfile, type OrganizationProfileData } from '@/features/platform/components/OrganizationProfile';
import { RiskBadges } from '@/features/platform/components/OrganizationRowParts';
import { SubscriptionActions } from '@/features/platform/components/SubscriptionActions';
import { SupportAccessPanel } from '@/features/platform/components/SupportAccessPanel';
import PlatformLayout from '@/layouts/platform-layout';
import { type InvoiceRow, type OrganizationRow, type SupportGrantRow } from '@/types/platform';
import { cilArrowLeft, cilBan, cilSwapHorizontal } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

interface ShowProps {
    organization: OrganizationRow;
    invoices: readonly InvoiceRow[];
    grants: readonly SupportGrantRow[];
    myGrant: { id: number; expiresAt: string } | null;
    plans: readonly { code: string; name: string }[];
    limits?: Readonly<Record<string, number | null>>;
    profile?: OrganizationProfileData;
    autoSuspendOn?: string | null;
    suspendable?: boolean;
}

function usage(used: number | null | undefined, limit: number | null | undefined): ReactNode {
    if (used === null || used === undefined) {
        return null;
    }

    return limit === null || limit === undefined ? (
        used
    ) : (
        <>
            {used} <span className="text-ink-3 text-sm font-semibold">/ {limit}</span>
        </>
    );
}

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="border-line flex flex-col gap-0.5 rounded-xl border px-3.5 py-3">
            <span className="text-ink-3 text-xs">{label}</span>
            <span className="text-lg font-extrabold">{children ?? '—'}</span>
        </div>
    );
}

export default function OrganizationShow({
    organization,
    invoices,
    grants,
    myGrant,
    plans,
    limits = {},
    profile,
    autoSuspendOn = null,
    suspendable = false,
}: ShowProps) {
    const [confirming, setConfirming] = useState(false);
    const counts = organization.counts;
    const volume = organization.volume;
    const overdue = invoices.find((invoice) => invoice.status === 'overdue');
    const created = new Intl.DateTimeFormat('es', { dateStyle: 'medium' }).format(new Date(organization.createdAt));

    return (
        <PlatformLayout
            title={organization.name}
            description={
                <div className="flex flex-col gap-2">
                    <span className="flex flex-wrap items-center gap-2">
                        <PlanPill code={organization.planCode} />
                        <OrganizationStatusBadge status={organization.status} />
                        {organization.subscriptionStatus ? <SubscriptionStatusBadge status={organization.subscriptionStatus} /> : null}
                        <RiskBadges row={organization} />
                    </span>
                    <span>
                        {organization.slug} · Creada el {created}
                        {counts ? ` · ${counts.users} ${counts.users === 1 ? 'usuario' : 'usuarios'}` : ''}
                    </span>
                </div>
            }
            back={
                <Link
                    href="/plataforma/organizaciones"
                    className="text-ink-2 hover:text-base-content inline-flex items-center gap-1.5 self-start text-[13px]"
                >
                    <Icon icon={cilArrowLeft} size={14} />
                    Organizaciones
                </Link>
            }
            actions={
                <>
                    <a href="#suscripcion" className="btn">
                        <Icon icon={cilSwapHorizontal} />
                        Cambiar plan
                    </a>
                    {suspendable ? (
                        <Button variant="danger" onClick={() => setConfirming(true)}>
                            <Icon icon={cilBan} />
                            Suspender
                        </Button>
                    ) : null}
                </>
            }
        >
            {overdue ? (
                <Alert tone="error" title={`Factura vencida el ${overdue.dueDate} · ${overdue.amount.usd}`}>
                    Pagado {overdue.paid.usd} hasta ahora.
                    {autoSuspendOn
                        ? ` Se suspende automáticamente el ${autoSuspendOn.slice(8, 10)}/${autoSuspendOn.slice(5, 7)} si no hay comprobante en revisión.`
                        : ''}
                </Alert>
            ) : null}

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1fr_400px] lg:gap-5">
                <div className="flex flex-col gap-4 lg:gap-5">
                    <SectionCard title="Uso" aria-label="Consumo">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <Tile label="Usuarios">{usage(counts?.users, limits.users)}</Tile>
                            <Tile label="Productos">{usage(counts?.products, limits.products)}</Tile>
                            <Tile label="Catálogos publicados">{usage(counts?.publishedCatalogs, limits.catalogs)}</Tile>
                            <Tile label="Clientes">{counts?.customers}</Tile>
                            <Tile label="Apartados activos">{usage(counts?.activeLayaways, limits.active_layaways)}</Tile>
                            <Tile label="Suscriptores">{counts?.subscribers}</Tile>
                        </div>
                    </SectionCard>

                    <SectionCard title="Volumen" subtitle="Agregado, sin datos personales de sus clientes" aria-label="Volumen">
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <Tile label="Comprometido">{volume?.committed.usd}</Tile>
                            <Tile label="Cobrado 30 d">{volume?.collectedLast30Days.usd}</Tile>
                            <Tile label="Pendiente">{volume?.outstanding.usd}</Tile>
                        </div>
                    </SectionCard>

                    <InvoiceList invoices={invoices} />
                </div>

                <div className="flex flex-col gap-4 lg:gap-5">
                    {profile ? <OrganizationProfile profile={profile} /> : null}
                    <SupportAccessPanel slug={organization.slug} myGrant={myGrant} grants={grants} />
                    <SubscriptionActions slug={organization.slug} plans={plans} currentPlan={organization.planCode} />
                </div>
            </div>
            <Modal
                open={confirming}
                onClose={() => setConfirming(false)}
                size="md"
                title={`Suspender ${organization.name}`}
                description="Sus catálogos se despublican y su equipo solo podrá cobrar y aprobar comprobantes hasta que pague. Sus clientes no pierden nada."
                actions={
                    <>
                        <Button variant="ghost" onClick={() => setConfirming(false)}>
                            Cancelar
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() =>
                                router.post(`/plataforma/organizaciones/${organization.slug}/pausar`, {}, { onFinish: () => setConfirming(false) })
                            }
                        >
                            Suspender
                        </Button>
                    </>
                }
            >
                <p className="text-ink-2 text-sm">Queda en la auditoría con tu nombre.</p>
            </Modal>
        </PlatformLayout>
    );
}
