import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { IconTextField } from '@/components/ui/icon-text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { cilArrowLeft, cilEnvelopeClosed, cilLockLocked, cilSend } from '@coreui/icons';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ForgotPasswordForm {
    [key: string]: string;
    email: string;
}

interface ForgotPasswordProps {
    status?: string | null;
}

export default function ForgotPassword({ status }: ForgotPasswordProps) {
    const { data, setData, post, processing, errors } = useForm<ForgotPasswordForm>({ email: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/recuperar');
    };

    return (
        <AccessLayout
            title="Recupera tu contraseña"
            description="Te enviaremos un enlace para crear una nueva. Vence en 60 minutos y solo funciona una vez."
            icon={cilLockLocked}
            footer={
                <Link href={route('login')} className="link link-primary link-hover inline-flex items-center gap-1.5 font-semibold">
                    <Icon icon={cilArrowLeft} size={14} />
                    Volver a entrar
                </Link>
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

                <Button type="submit" block loading={processing} className="h-12 text-[15px]">
                    {processing ? null : <Icon icon={cilSend} />}
                    Enviar enlace
                </Button>

                {status ? (
                    <Alert tone="success" title="Revisa tu correo">
                        {status}
                    </Alert>
                ) : null}
            </form>
        </AccessLayout>
    );
}
