import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconTextField } from '@/components/ui/icon-text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { cilEnvelopeClosed, cilLockLocked, cilShieldAlt } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface LoginForm {
    [key: string]: string;
    email: string;
    password: string;
}

export default function PlatformLogin({ status }: { status?: string | null }) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({ email: '', password: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post('/plataforma/login', { onFinish: () => reset('password') });
    };

    return (
        <AccessLayout
            title="Plataforma Apartar"
            description="Solo para el equipo de Apartar. Todas las acciones quedan auditadas."
            status={status}
            badge={
                <span className="bg-error-soft text-error inline-flex items-center gap-1.5 self-start rounded-full px-2.5 py-[3px] text-xs font-semibold">
                    <Icon icon={cilShieldAlt} size={12} />
                    Acceso restringido
                </span>
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
                    autoComplete="username"
                    autoFocus
                    required
                />
                <IconTextField
                    label="Contraseña"
                    name="password"
                    type="password"
                    icon={cilLockLocked}
                    value={data.password}
                    onChange={(event) => setData('password', event.target.value)}
                    error={errors.password}
                    autoComplete="current-password"
                    required
                />
                <Button type="submit" loading={processing} block className="h-12 text-[15px]">
                    Entrar
                </Button>
            </form>
        </AccessLayout>
    );
}
