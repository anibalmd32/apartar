import { BusinessBlock } from '@/features/layaway/components/BusinessBlock';
import { PortalAlerts } from '@/features/layaway/components/PortalAlerts';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalDashboardProps } from '@/types/portal';

/**
 * La portada del portal (`REQ-LAY-020`).
 *
 * Primero las alertas (RB-8, `REQ-LAY-023`), y debajo un bloque por negocio,
 * en el orden en que llegan —el vencimiento más próximo primero (RB-4)— y
 * nunca mezclados (RB-2). Con la sesión acotada hay exactamente uno, y el
 * agregado de todos (`totals`) no existe.
 */
export default function Dashboard({ session, businesses, alerts }: PortalDashboardProps) {
    const businessName = businesses.length === 1 ? (businesses[0]?.business.name ?? 'Mis apartados') : 'Mis apartados';

    return (
        <PortalLayout title={`Mis apartados · ${businessName}`} businessName={businessName} customerName={session.displayName}>
            <h1 className="text-xl font-semibold">Tus apartados</h1>

            <PortalAlerts alerts={alerts} />

            {businesses.length === 0 ? (
                <p className="text-sm opacity-70">No tienes apartados todavía.</p>
            ) : (
                businesses.map((block) => <BusinessBlock key={block.business.slug} block={block} />)
            )}
        </PortalLayout>
    );
}
