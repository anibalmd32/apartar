import { Button } from '@/components/ui/button';
import { IconTextField } from '@/components/ui/icon-text-field';
import { PasswordRequirements } from '@/layouts/auth/password-requirements';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { cilEnvelopeClosed, cilLockLocked } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

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
        <AccessLayout title="Elige tu contraseña nueva" icon={cilLockLocked}>
            <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
                <IconTextField
                    label="Correo"
                    name="email"
                    type="email"
                    icon={cilEnvelopeClosed}
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                    autoComplete="email"
                    readOnly
                    required
                />

                <IconTextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    revealable
                    describedBy="password-requirements"
                    autoComplete="new-password"
                    autoFocus
                    required
                />

                <PasswordRequirements id="password-requirements" value={data.password} />

                <IconTextField
                    label="Repite la contraseña"
                    name="password_confirmation"
                    type="password"
                    value={data.password_confirmation}
                    onChange={(event) => setData('password_confirmation', event.target.value)}
                    autoComplete="new-password"
                    required
                />

                <Button type="submit" block loading={processing} className="h-12 text-[15px]">
                    Guardar y entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
