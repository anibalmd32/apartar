import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Elegir contraseña nueva desde el enlace de recuperación
 * (`REQ-BOF-001` RB-12, SP-10).
 *
 * El token viaja en un campo oculto porque el enlace se consume **una sola
 * vez**: si la persona recarga después de usarlo, el servidor responde que ya
 * no sirve y le ofrece pedir otro, en vez de fallar sin explicación.
 */

interface ResetPasswordForm {
    [key: string]: string;
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
}

interface ResetPasswordProps {
    token: string;
    email: string;
}

export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm<ResetPasswordForm>({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/restablecer', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AccessLayout title="Elige tu contraseña nueva">
            <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
                <TextField
                    label="Correo"
                    name="email"
                    type="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                    autoComplete="email"
                    readOnly
                    required
                />

                <TextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    hint="Doce caracteres o más, con mayúsculas, minúsculas y números."
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
                    Guardar y entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
