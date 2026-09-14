import { Alert } from '@/components/ui/alert';
import { Head } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Marco de las pantallas de acceso al backoffice (`REQ-BOF-001`).
 *
 * Las cuatro —entrar, pedir enlace, restablecer y el cambio obligatorio— son
 * la misma tarjeta con distinto formulario, así que el marco está una vez. Va
 * aparte de `BackofficeLayout` porque aquí **no hay sesión todavía**: no hay
 * menú que filtrar por rol ni negocio del que avisar.
 */

interface AccessLayoutProps {
    title: string;
    description?: string;
    status?: string | null;
    children: ReactNode;
    footer?: ReactNode;
}

export default function AccessLayout({ title, description, status, children, footer }: AccessLayoutProps) {
    return (
        <main className="flex min-h-screen items-start justify-center p-4 sm:items-center">
            <Head title={title} />

            <div className="card bg-base-100 w-full max-w-md shadow-sm">
                <div className="card-body gap-4">
                    <header>
                        <h1 className="card-title text-xl">{title}</h1>
                        {description ? <p className="text-sm opacity-70">{description}</p> : null}
                    </header>

                    {status ? <Alert tone="success">{status}</Alert> : null}

                    {children}

                    {footer ? <div className="text-center text-sm opacity-70">{footer}</div> : null}
                </div>
            </div>
        </main>
    );
}
