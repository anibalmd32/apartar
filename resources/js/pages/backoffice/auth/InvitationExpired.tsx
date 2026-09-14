import { Alert } from '@/components/ui/alert';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { Link } from '@inertiajs/react';

/**
 * El enlace ya no vale (`REQ-ORG-004` SP-3).
 *
 * **Quien llega aquí no ha hecho nada mal**, y la pantalla tiene que decirlo:
 * lo normal es que tardara tres días en abrir el correo. Un `404`, un «token
 * inválido» o un error crudo le harían pensar que el problema es suyo o que la
 * invitación nunca existió, y la mayoría no volvería a preguntar.
 *
 * No hay botón que reenvíe solo: sería un endpoint sin autenticar capaz de
 * disparar correos con un token cualquiera, y quien decide si esa persona
 * sigue entrando al equipo es el `owner`. Tampoco se muestra su correo —sería
 * revelar un dato de la organización a quien todavía no está dentro—, así que
 * se le dice que se lo pida a quien le invitó, que es alguien que conoce.
 */

interface InvitationExpiredProps {
    organizationName: string | null;
}

export default function InvitationExpired({ organizationName }: InvitationExpiredProps) {
    const business = organizationName === null ? 'ese negocio' : organizationName;

    return (
        <AccessLayout
            title="Este enlace ya no sirve"
            description={`Las invitaciones a ${business} valen 48 horas y una sola vez.`}
            footer={
                <>
                    ¿Ya tienes cuenta? <Link href="/backoffice/login">Entrar</Link>
                </>
            }
        >
            <Alert tone="info">
                Pídele a quien te invitó que te lo reenvíe desde <strong>Equipo</strong>. Tu sitio sigue reservado:
                no hace falta empezar de cero.
            </Alert>
        </AccessLayout>
    );
}
