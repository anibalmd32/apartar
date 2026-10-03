import PortalLayout from '@/layouts/portal-layout';
import { type PortalDashboardProps } from '@/types/portal';

/**
 * La portada del portal con la sesión acotada (`REQ-IDN-008` RB-4).
 *
 * **Versión mínima de `T-049`**: la lista de **todos** los apartados de la
 * ficha en este negocio, no solo el del código con el que se entró. Los
 * saldos, el próximo vencimiento y las alertas son de `T-051`.
 */
export default function Dashboard({ organization, customer, layaways }: PortalDashboardProps) {
    return (
        <PortalLayout title={`Mis apartados · ${organization.name}`} businessName={organization.name} customerName={customer.fullName}>
            <section className="flex flex-col gap-1">
                <h1 className="text-xl font-semibold">Tus apartados en {organization.name}</h1>
                <p className="text-sm opacity-70">{customer.documentNumber}</p>
            </section>

            {layaways.length === 0 ? (
                <p className="text-sm opacity-70">No tienes apartados en este negocio.</p>
            ) : (
                <ul className="flex flex-col gap-3">
                    {layaways.map((layaway) => (
                        <li key={layaway.code} className="card bg-base-100 shadow-sm">
                            <div className="card-body gap-1 p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <p className="font-medium">
                                        {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                                        {layaway.productName}
                                    </p>
                                    <span className="badge badge-ghost shrink-0">{layaway.statusLabel}</span>
                                </div>
                                <p className="text-sm opacity-70">
                                    <span className="font-mono">{layaway.code}</span> · Total {layaway.total}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </PortalLayout>
    );
}
