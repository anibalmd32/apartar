import { Head, Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Marco del portal privado del cliente (`REQ-LAY-020`, `REQ-IDN-004`).
 *
 * La cabecera dice **con qué negocio** se está viendo la cuenta: la sesión
 * acotada es de uno solo, y la persona puede tener apartados en varios. Y
 * ofrece salir, que cierra la sesión entera (`REQ-IDN-004` RB-5).
 */

interface PortalLayoutProps {
    title: string;
    businessName: string;
    customerName: string;
    children: ReactNode;
}

export default function PortalLayout({ title, businessName, customerName, children }: PortalLayoutProps) {
    return (
        <div className="bg-base-200 min-h-screen">
            <Head title={title} />

            <header className="bg-base-100 border-base-300 border-b">
                <div className="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3">
                    <div className="min-w-0">
                        <p className="truncate font-semibold">{businessName}</p>
                        <p className="truncate text-sm opacity-70">{customerName}</p>
                    </div>

                    <Link href={route('portal.logout')} method="post" as="button" className="btn btn-ghost btn-sm">
                        Salir
                    </Link>
                </div>
            </header>

            <main className="mx-auto flex max-w-3xl flex-col gap-4 px-4 py-6">{children}</main>
        </div>
    );
}
