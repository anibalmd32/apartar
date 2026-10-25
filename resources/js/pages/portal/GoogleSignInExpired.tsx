import AccessLayout from '@/layouts/backoffice/access-layout';
import { type GoogleSignInExpiredProps } from '@/types/account';
import { cilClock } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export default function GoogleSignInExpired({ retryUrl, backUrl }: GoogleSignInExpiredProps) {
    return (
        <AccessLayout title="El inicio de sesión caducó" icon={cilClock} iconTone="warning" centered>
            <p role="alert" className="text-ink-2 -mt-1 text-center text-sm leading-normal">
                Pasó demasiado tiempo desde que saliste hacia Google. Vuelve a intentarlo.
            </p>

            <a href={retryUrl} className="btn btn-primary btn-lg btn-block">
                Entrar con Google otra vez
            </a>

            <Link href={backUrl} className="btn btn-lg btn-block">
                Volver sin cuenta
            </Link>
        </AccessLayout>
    );
}
