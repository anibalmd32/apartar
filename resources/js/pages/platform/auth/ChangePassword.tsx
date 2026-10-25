import { Button } from '@/components/ui/button';
import { IconTextField } from '@/components/ui/icon-text-field';
import { PasswordRequirements } from '@/layouts/auth/password-requirements';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { cilShieldAlt } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ChangePasswordForm {
    [key: string]: string;
    password: string;
    password_confirmation: string;
}

export default function PlatformChangePassword() {
    const { data, setData, put, processing, errors, reset } = useForm<ChangePasswordForm>({ password: '', password_confirmation: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put('/plataforma/contrasena', { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <AccessLayout
            title="Elige una contraseña nueva"
            description="La que vino con el despliegue no se puede seguir usando."
            icon={cilShieldAlt}
            iconTone="warning"
        >
            <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
                <IconTextField
                    label="Contraseña nueva"
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
                <Button type="submit" loading={processing} block className="h-12 text-[15px]">
                    Guardar y entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
