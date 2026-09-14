import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Cambio de contraseña obligatorio (`REQ-BOF-001` RB-5).
 *
 * Es la única pantalla alcanzable mientras `must_change_password` esté puesto,
 * así que no ofrece salida lateral: el menú no está y no hay enlaces a otras
 * secciones. Si se pudiera navegar «solo un poco», la rotación sería opcional
 * y la contraseña que se quería retirar seguiría en uso.
 *
 * No pide la contraseña actual: quien llega suele tener una que le acaban de
 * asignar, o una que se ha decidido retirar.
 */

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
            title="Elige una contraseña nueva"
            description="Antes de seguir, tienes que cambiar la contraseña con la que entraste."
        >
            <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
                <TextField
                    label="Contraseña nueva"
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
                    Guardar y continuar
                </Button>
            </form>
        </AccessLayout>
    );
}
