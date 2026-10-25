import AccessLayout from '@/layouts/backoffice/access-layout';
import { cilAvTimer } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface InvitationExpiredProps {
    organizationName: string | null;
}

export default function InvitationExpired({ organizationName }: InvitationExpiredProps) {
    const business = organizationName === null ? 'ese negocio' : organizationName;

    return (
        <AccessLayout
            title="Esta invitación ya no es válida"
            description={
                <>
                    Las invitaciones a {business} valen 48 horas y una sola vez. Pídele a quien te invitó que te envíe una nueva desde la sección{' '}
                    <strong className="text-base-content font-semibold">Equipo</strong>: tu sitio sigue reservado.
                </>
            }
            icon={cilAvTimer}
            iconTone="warning"
            centered
            footer={
                <p className="text-ink-3 text-[13px]">
                    ¿Ya tienes cuenta?{' '}
                    <Link href="/backoffice/login" className="link link-primary link-hover ml-1 font-semibold">
                        Entrar
                    </Link>
                </p>
            }
        >
            <Link href={route('home')} className="btn btn-block h-12 text-[15px]">
                Ir a Apartar
            </Link>
        </AccessLayout>
    );
}
