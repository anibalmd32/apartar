import PlatformLayout from '@/layouts/platform-layout';
import { Link } from '@inertiajs/react';

export default function SupportRequired({ message, organizationUrl }: { message: string; organizationUrl: string | null }) {
    return (
        <PlatformLayout title="Acceso de soporte necesario">
            <p className="border-line bg-base-100 text-ink-2 rounded-2xl border p-6 text-sm">{message}</p>
            {organizationUrl !== null ? (
                <Link href={organizationUrl} className="btn btn-primary btn-sm self-start">
                    Pedir acceso desde la ficha del negocio
                </Link>
            ) : null}
        </PlatformLayout>
    );
}
