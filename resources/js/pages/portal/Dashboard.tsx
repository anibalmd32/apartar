import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FeedList } from '@/features/account/components/FeedList';
import { PortalSummary } from '@/features/account/components/PortalSummary';
import { BusinessBlock } from '@/features/layaway/components/BusinessBlock';
import { PortalAlerts } from '@/features/layaway/components/PortalAlerts';
import { PushOptIn } from '@/features/notifications/components/PushOptIn';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalDashboardProps } from '@/types/portal';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export const VISIBLE_BUSINESSES = 5;

export default function Dashboard({
    session,
    businesses,
    totals,
    alerts,
    connectGoogleUrl,
    subscriptionsUrl,
    claimUrl,
    feedPreview,
    push,
}: PortalDashboardProps) {
    const [showAll, setShowAll] = useState(false);
    const single = businesses.length === 1 ? businesses[0] : undefined;
    const businessName = session.mode === 'scoped' && single !== undefined ? single.business.name : 'Mi cuenta';
    const visible = showAll ? businesses : businesses.slice(0, VISIBLE_BUSINESSES);
    const firstName = session.displayName.split(' ')[0] ?? session.displayName;
    const summary =
        totals !== null && businesses.length > 1 ? totals : single !== undefined && single.totals.activeLayaways > 0 ? single.totals : null;

    return (
        <PortalLayout title={`Mis apartados · ${businessName}`} businessName={businessName} customerName={session.displayName}>
            <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.5px] lg:text-[32px]">
                Hola, {firstName} <span aria-hidden="true">👋</span>
            </h1>

            <PortalAlerts alerts={alerts} />

            <PushOptIn publicKey={push.publicKey} subscribeUrl={push.subscribeUrl} hasEmail={push.hasEmail} />

            {summary !== null ? (
                <PortalSummary totals={summary} label={businesses.length > 1 ? 'Resumen de todos tus negocios' : 'Resumen de tus apartados'} />
            ) : null}

            {businesses.length === 0 ? (
                <EmptyAccount isAccount={session.mode === 'account'} subscriptionsUrl={subscriptionsUrl} claimUrl={claimUrl} />
            ) : (
                <>
                    {visible.map((block) => (
                        <BusinessBlock key={block.business.slug} block={block} />
                    ))}

                    {!showAll && businesses.length > VISIBLE_BUSINESSES ? (
                        <Button variant="secondary" block onClick={() => setShowAll(true)}>
                            Ver todos mis negocios ({businesses.length})
                        </Button>
                    ) : null}
                </>
            )}

            {claimUrl !== null && businesses.length > 0 ? (
                <p className="text-ink-2 text-sm">
                    ¿Falta algún apartado de antes?{' '}
                    <Link href={claimUrl} className="text-primary-ink font-semibold hover:underline">
                        Recupéralo con tu cédula y su código
                    </Link>
                </p>
            ) : null}

            {feedPreview !== null && feedPreview.items.length > 0 ? (
                <section aria-labelledby="feed-heading" className="flex flex-col gap-3 lg:gap-4">
                    <div className="flex items-center justify-between gap-3">
                        <h2 id="feed-heading" className="text-[17px] font-bold">
                            Novedades de tus catálogos
                        </h2>
                        <Link href={feedPreview.url} className="text-primary-ink text-[13px] font-semibold hover:underline">
                            Ver todo
                        </Link>
                    </div>
                    <FeedList items={feedPreview.items} />
                </section>
            ) : null}

            {connectGoogleUrl !== null ? (
                <Alert tone="info">
                    <span className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            Conecta tu cuenta de Google para ver juntos tus apartados de todos los negocios y enterarte de lo nuevo de los catálogos
                            que sigas.
                        </span>
                        <a href={connectGoogleUrl} className="btn btn-sm">
                            Conectar Google
                        </a>
                    </span>
                </Alert>
            ) : null}
        </PortalLayout>
    );
}

function EmptyAccount({ isAccount, subscriptionsUrl, claimUrl }: { isAccount: boolean; subscriptionsUrl: string | null; claimUrl: string | null }) {
    if (!isAccount) {
        return <p className="text-ink-3 text-sm">No tienes apartados todavía.</p>;
    }

    return (
        <div className="border-line bg-base-100 flex flex-col items-start gap-2 rounded-2xl border p-6 text-sm">
            <p className="text-base font-bold">Aquí verás tus apartados de todos los negocios.</p>
            <p className="text-ink-2">
                Los que hagas con esta cuenta abierta aparecen solos. Si ya apartaste antes sin ella, tráelo con tu cédula y el código de ese
                apartado.
            </p>
            {claimUrl !== null ? (
                <Link href={claimUrl} className="btn btn-primary btn-sm mt-1">
                    Recuperar mi historial
                </Link>
            ) : null}
            {subscriptionsUrl !== null ? (
                <Link href={subscriptionsUrl} className="text-primary-ink font-semibold hover:underline">
                    Ver los catálogos que sigues
                </Link>
            ) : null}
        </div>
    );
}
