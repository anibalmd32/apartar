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

export default function ChangePassword() {
    const { data, setData, post, processing, errors, reset } = useForm<ChangePasswordForm>({
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/cambiar-contrasena', {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AccessLayout
            title="Crea una nueva contraseña"
            description="Por seguridad debes cambiarla antes de continuar."
            icon={cilShieldAlt}
            iconTone="warning"
        >
            <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
                <IconTextField
                    label="Nueva contraseña"
                    name="password"
                    type="password"
                    revealable
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
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
