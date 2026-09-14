import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Elegir la contraseña con la que se entra a un equipo
 * (`REQ-ORG-004` RB-5).
 *
 * Reutiliza el marco de acceso: es una puerta más del backoffice y no tiene
 * por qué parecer otra cosa. Y el correo se muestra **deshabilitado y no
 * editable**, que es la diferencia con el registro: aquí la dirección no la
 * elige quien mira la pantalla, la eligió quien invitó, y es la que sostiene
 * `users.email` único en toda la plataforma.
 */

interface AcceptInvitationProps {
    token: string;
    name: string;
    email: string;
    organizationName: string | null;
}

interface AcceptForm {
    [key: string]: string;
    password: string;
    password_confirmation: string;
}

export default function AcceptInvitation({ token, name, email, organizationName }: AcceptInvitationProps) {
    const { data, setData, post, processing, errors, reset } = useForm<AcceptForm>({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(`/invitacion/${token}`, {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AccessLayout
            title={`Hola, ${name}`}
            description={
                organizationName === null
                    ? 'Elige tu contraseña para entrar.'
                    : `Te sumaron al equipo de ${organizationName}. Elige tu contraseña para entrar.`
            }
        >
            <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
                <TextField label="Tu correo" name="email" type="email" value={email} readOnly disabled />

                <TextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    hint="Mínimo 12 caracteres, con mayúsculas, minúsculas y números."
                    autoComplete="new-password"
                    autoFocus
                    required
                />

                <TextField
                    label="Repite la contraseña"
                    name="password_confirmation"
                    type="password"
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                    required
                />

                <Button type="submit" block loading={processing} className="mt-2">
                    Entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
