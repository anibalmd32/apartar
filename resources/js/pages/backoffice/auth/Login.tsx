import { Button } from '@/components/ui/button';
import { IconTextField } from '@/components/ui/icon-text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { cilEnvelopeClosed, cilLockLocked } from '@coreui/icons';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

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
            description="Gestiona tus catálogos, apartados y cobros."
            status={status}
            footer={
                <div className="border-line flex flex-col gap-3 border-t pt-4">
                    <p>
                        ¿Aún no tienes cuenta?{' '}
                        <Link href={route('register')} className="link link-primary link-hover ml-1 font-bold">
                            Crear negocio gratis
                        </Link>
                    </p>
                    <p className="text-ink-3 text-[13px]">
                        ¿Eres cliente?{' '}
                        <Link href={route('portal.access')} className="text-ink-2 link-hover ml-1 font-semibold">
                            Entra a Mi cuenta
                        </Link>
                    </p>
                </div>
            }
        >
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
                    autoFocus
                    required
                />

                <IconTextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    icon={cilLockLocked}
                    revealable
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    autoComplete="current-password"
                    required
                />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <label className="text-ink-2 flex cursor-pointer items-center gap-2.5 text-[13px]">
                        <input
                            type="checkbox"
                            className="checkbox checkbox-sm rounded-[5px]"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                        />
                        Recordarme
                    </label>

                    <Link href={route('password.request')} className="link link-primary link-hover text-[13px] font-semibold">
                        ¿Olvidaste tu contraseña?
                    </Link>
                </div>

                <Button type="submit" block loading={processing} className="h-12 text-[15px]">
                    Entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
