import { Alert } from '@/components/ui/alert';
import { type BackofficeSharedData } from '@/types/backoffice';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import { ANY_CAPABILITY, BackofficeNav, type NavSection } from '@/layouts/backoffice/nav';

/**
 * El marco de todas las pantallas del backoffice (`REQ-BOF-001`).
 *
 * Tres cosas viven aquí y no en cada página, porque las tres tienen que
 * cumplirse en las cuarenta que vienen:
 *
 *  1. **La navegación se filtra por capacidad, no por rol.** Cada sección
 *     declara el área de `REQ-ORG-003` RB-1 que hay que ejercer, y el reparto
 *     —qué es gobierno y qué es operación diaria— vive en
 *     `OrganizationCapability::requiresOwner()` y llega resuelto en las props.
 *     Hasta `T-014` esto era un `ownerOnly` por sección, es decir, la frontera
 *     escrita dos veces; coincidían, y habrían dejado de coincidir el día que
 *     una capacidad cambiara de lado. Ocultar no es control de acceso —eso es
 *     la policy del servidor— pero ofrecer lo que va a dar `403` es una forma
 *     de maltratar a quien confía en el menú.
 *  2. **El aviso de suspensión.** Va en el marco y no en el panel porque tiene
 *     que verse esté donde esté la persona: un equipo que no entiende por qué
 *     no puede crear nada no va a regularizar nada.
 *  3. **El `status`** de la última operación, para no repetir el mismo bloque
 *     de mensaje en cada pantalla.
 */

interface BackofficeLayoutProps {
    title: string;
    description?: string;
    children: ReactNode;
}

/**
 * Secciones del menú. Cada una declara **la capacidad que hay que ejercer**
 * para verla, no el rol: el reparto de `REQ-ORG-003` RB-1 vive en
 * `OrganizationCapability::requiresOwner()` y llega resuelto en las props. Las
 * que faltan se añaden aquí con su hito.
 */
const SECTIONS: NavSection[] = [
    { label: 'Panel', href: '/backoffice', capability: ANY_CAPABILITY },
    { label: 'Equipo', href: '/backoffice/equipo', capability: 'team' },
    { label: 'Ajustes', href: '/backoffice/ajustes', capability: 'settings' },
    { label: 'Cuentas de cobro', href: '/backoffice/cuentas-de-cobro', capability: 'payment_accounts' },
    { label: 'Auditoría', href: '/backoffice/auditoria', capability: 'audit' },
];

export default function BackofficeLayout({ title, description, children }: BackofficeLayoutProps) {
    const { teamMember, status } = usePage<BackofficeSharedData>().props;

    const organization = teamMember?.organization;
    const suspended = organization?.status === 'suspended';

    const logout = () => router.post('/backoffice/logout');

    return (
        <div className="bg-base-200 min-h-screen">
            <Head title={title} />

            <header className="navbar bg-base-100 border-base-300 border-b px-4">
                <div className="flex-1">
                    <Link href="/backoffice" className="text-base font-semibold">
                        {organization?.name ?? 'Apartar'}
                    </Link>
                </div>

                <div className="flex-none items-center gap-2">
                    <span className="hidden text-sm opacity-70 sm:inline">{teamMember?.name}</span>

                    <button type="button" className="btn btn-ghost btn-sm" onClick={logout}>
                        Salir
                    </button>
                </div>
            </header>

            <BackofficeNav sections={SECTIONS} capabilities={teamMember?.capabilities ?? []} />

            <main className="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4">
                {suspended ? (
                    <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                        <span>
                            Tu suscripción está suspendida por falta de pago. Puedes cobrar y aprobar comprobantes, pero no crear cosas nuevas.
                        </span>

                        {/* La pantalla de suscripción llega con su hito; el
                            enlace ya apunta a su URL definitiva para que el
                            aviso esté completo el día que exista. */}
                        <a className="btn btn-sm btn-warning" href="/backoffice/suscripcion">
                            Regularizar el pago
                        </a>
                    </Alert>
                ) : null}

                {status ? <Alert tone="success">{status}</Alert> : null}

                <section className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold">{title}</h1>
                    {description ? <p className="text-sm opacity-70">{description}</p> : null}
                </section>

                {children}
            </main>
        </div>
    );
}
