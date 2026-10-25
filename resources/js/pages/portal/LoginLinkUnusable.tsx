import AccessLayout from '@/layouts/backoffice/access-layout';
import { type PortalLoginLinkUnusableProps } from '@/types/portal';
import { cilClock } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export default function LoginLinkUnusable({ message }: PortalLoginLinkUnusableProps) {
    return (
        <AccessLayout title="Este enlace ya no sirve" icon={cilClock} iconTone="warning" centered>
            <p role="alert" className="text-ink-2 -mt-1 text-center text-sm leading-normal">
                {message}
            </p>

            <Link href={route('portal.access')} className="btn btn-primary btn-lg btn-block">
                Volver a entrar
            </Link>
        </AccessLayout>
    );
}
