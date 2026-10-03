import { Alert } from '@/components/ui/alert';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { type PortalLoginLinkUnusableProps } from '@/types/portal';
import { Link } from '@inertiajs/react';

/**
 * Un enlace de acceso que ya no sirve (`REQ-IDN-003` SP-1, SP-2).
 *
 * Caducado, ya usado o inexistente: **la misma pantalla** para los tres, con
 * la salida de pedir otro o entrar con el código. Distinguirlos solo le
 * serviría a quien prueba enlaces.
 */
export default function LoginLinkUnusable({ message }: PortalLoginLinkUnusableProps) {
    return (
        <AccessLayout title="Este enlace ya no sirve">
            <Alert tone="warning">{message}</Alert>

            <Link href={route('portal.access')} className="btn btn-primary btn-block">
                Volver a entrar
            </Link>
        </AccessLayout>
    );
}
