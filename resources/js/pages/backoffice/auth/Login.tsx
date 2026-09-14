import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Entrada al backoffice (`REQ-BOF-001`).
 *
 * **No hay selector de organización** (RB-2): el correo es único en toda la
 * plataforma, así que identifica a la persona y con ella a su negocio. Si esta
 * pantalla llegara a pedir de qué organización es alguien, sería la señal de
 * que el modelo de identidad se torció.
 *
 * El error de credenciales es genérico y llega en el campo del correo (SP-1).
 * No distingue «ese correo no existe» de «esa contraseña está mal», porque
 * distinguirlos convertiría este formulario en un oráculo que dice si una
 * persona trabaja en algún negocio de la plataforma.
 */

interface LoginForm {
    [key: string]: string | boolean;
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string | null;
}

export default function Login({ status }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/login', {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AccessLayout
            title="Entra a tu negocio"
            description="Con el correo de tu cuenta de equipo."
            status={status}
            footer={
                <>
                    ¿Aún no tienes negocio?{' '}
                    <Link href={route('register')} className="link link-primary">
                        Créalo gratis
                    </Link>
                </>
            }
        >
            <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
                <TextField
                    label="Correo"
                    name="email"
                    type="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    error={errors.email}
                    autoComplete="email"
                    autoFocus
                    required
                />

                <TextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    autoComplete="current-password"
                    required
                />

                <label className="label cursor-pointer justify-start gap-2">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-sm"
                        checked={data.remember}
                        onChange={(event) => setData('remember', event.target.checked)}
                    />
                    <span className="label-text text-sm">Mantener la sesión abierta</span>
                </label>

                <Button type="submit" block loading={processing} className="mt-2">
                    Entrar
                </Button>

                <Link href={route('password.request')} className="link link-hover mt-1 text-center text-sm">
                    Olvidé mi contraseña
                </Link>
            </form>
        </AccessLayout>
    );
}
