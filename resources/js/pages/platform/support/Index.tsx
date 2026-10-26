import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { Pager } from '@/features/platform/components/Pager';
import PlatformLayout from '@/layouts/platform-layout';
import { PlatformSupportTabs } from '@/layouts/platform/nav';
import { type SupportGrantListRow } from '@/types/platform';
import { cilShieldAlt } from '@coreui/icons';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

type GrantFilter = 'vigentes' | 'todos';

interface SupportIndexProps {
    grants: readonly SupportGrantListRow[];
    filter: GrantFilter;
    page: number;
    hasMore: boolean;
}

const TABS = [
    { value: 'vigentes', label: 'Vigentes' },
    { value: 'todos', label: 'Todos' },
] as const;

export default function SupportIndex({ grants, filter, page, hasMore }: SupportIndexProps) {
    const [revoking, setRevoking] = useState<SupportGrantListRow | null>(null);
    const time = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });
    const visit = (changes: Record<string, string | number>) =>
        router.get('/plataforma/soporte', { estado: filter, pagina: 1, ...changes }, { preserveState: true, replace: true });

    return (
        <PlatformLayout
            title="Accesos de soporte"
            description="Accesos temporales de solo lectura a los datos de un negocio. El negocio ve un aviso mientras están abiertos."
        >
            <PlatformSupportTabs />

            <FilterTabs<GrantFilter> label="Estado del acceso" tabs={TABS} value={filter} onChange={(estado) => visit({ estado })} />

            <section aria-label="Accesos" className="border-line bg-base-100 shadow-card overflow-hidden rounded-2xl border">
                {grants.length === 0 ? (
                    <div className="p-6">
                        <EmptyState icon={cilShieldAlt} title={filter === 'vigentes' ? 'Ningún acceso abierto' : 'Todavía no hay accesos'}>
                            Los accesos se abren desde el detalle de cada organización, con un motivo que el negocio verá.
                        </EmptyState>
                    </div>
                ) : (
                    <ul className="divide-line flex flex-col divide-y">
                        {grants.map((grant) => (
                            <li key={grant.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-start sm:gap-4">
                                <div className="flex min-w-0 flex-1 flex-col gap-1">
                                    <span className="flex flex-wrap items-center gap-2">
                                        {grant.active ? (
                                            <Badge tone="warning">Vigente</Badge>
                                        ) : (
                                            <Badge>{grant.revokedAt ? 'Cerrado' : 'Caducado'}</Badge>
                                        )}
                                        {grant.organization.slug ? (
                                            <Link
                                                href={`/plataforma/organizaciones/${grant.organization.slug}`}
                                                className="text-sm font-semibold hover:underline"
                                            >
                                                {grant.organization.name}
                                            </Link>
                                        ) : (
                                            <span className="text-sm font-semibold">{grant.organization.name}</span>
                                        )}
                                    </span>
                                    <p className="text-ink-2 text-sm">{grant.reason}</p>
                                    <p className="text-ink-3 text-xs">
                                        {grant.operator} · abierto el {time.format(new Date(grant.grantedAt))} ·{' '}
                                        {grant.revokedAt
                                            ? `cerrado el ${time.format(new Date(grant.revokedAt))}`
                                            : `${grant.active ? 'caduca' : 'caducó'} el ${time.format(new Date(grant.expiresAt))}`}
                                    </p>
                                </div>
                                {grant.active && grant.organization.slug ? (
                                    <Button variant="secondary" size="sm" onClick={() => setRevoking(grant)}>
                                        Cerrar acceso
                                    </Button>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
                <Pager page={page} hasMore={hasMore} onPage={(pagina) => visit({ pagina })} />
            </section>

            <ConfirmDialog
                open={revoking !== null}
                onClose={() => setRevoking(null)}
                title="¿Cerrar este acceso de soporte?"
                description={revoking ? `El operador deja de ver los datos de ${revoking.organization.name} en su siguiente petición.` : undefined}
                confirmLabel="Cerrar acceso"
                url={revoking ? `/plataforma/organizaciones/${revoking.organization.slug}/soporte/${revoking.id}` : ''}
                options={{ onSuccess: () => setRevoking(null) }}
            />
        </PlatformLayout>
    );
}
