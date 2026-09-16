import BackofficeLayout from '@/layouts/backoffice-layout';
import { type BackofficeSharedData } from '@/types/backoffice';
import { usePage } from '@inertiajs/react';

/**
 * El panel del negocio, vacío a propósito (`REQ-BOF-001`).
 *
 * El checklist de arranque de `REQ-ORG-001` —verificar el correo, crear una
 * cuenta de cobro, cargar el primer producto, armar el primer catálogo— llega
 * cuando existan esas cosas. Mientras tanto, esto dice la verdad en lugar de
 * enseñar seis tarjetas en cero: un panel lleno de métricas vacías hace
 * pensar que el producto no funciona, y un hueco honesto no.
 */
export default function Dashboard() {
    const { teamMember } = usePage<BackofficeSharedData>().props;

    return (
        <BackofficeLayout
            title={`Hola, ${teamMember?.name.split(' ')[0] ?? ''}`.trim()}
            description={`Este es el panel de ${teamMember?.organization.name ?? 'tu negocio'}.`}
        >
            <div className="card bg-base-100 shadow-sm">
                <div className="card-body gap-3">
                    <h2 className="card-title text-base">Todavía no hay nada que mostrar aquí</h2>

                    <p className="text-sm opacity-70">
                        Cuando cargues tus productos y armes tu primer catálogo, este panel mostrará tu cobranza del día: cuotas por vencer,
                        comprobantes por revisar y apartados en curso.
                    </p>

                    <p className="text-sm opacity-70">
                        Tu enlace público será <span className="font-mono">apartar.store/{teamMember?.organization.slug}</span>.
                    </p>
                </div>
            </div>
        </BackofficeLayout>
    );
}
