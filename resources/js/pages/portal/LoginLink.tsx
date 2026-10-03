import { Button } from '@/components/ui/button';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { type PortalLoginLinkProps } from '@/types/portal';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Confirmar la entrada desde el enlace del correo (`REQ-IDN-003` RB-9).
 *
 * El enlace del correo abre **esta** pantalla y no la sesión: el canje es un
 * `POST` que solo sale de este botón. Así, el cliente de correo que precarga
 * los enlaces para enseñar una vista previa no gasta el token de un solo uso
 * antes de que la persona lo pulse.
 */
export default function LoginLink({ token }: PortalLoginLinkProps) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.link.consume', { token }));
    };

    return (
        <AccessLayout title="Entra a tus apartados" description="Pulsa para entrar con el enlace que te enviamos.">
            <form onSubmit={submit}>
                <Button type="submit" block loading={processing}>
                    Entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
