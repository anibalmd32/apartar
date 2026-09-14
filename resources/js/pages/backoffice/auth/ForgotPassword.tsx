import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Pedir el enlace de recuperación (`REQ-BOF-001` RB-12).
 *
 * El aviso de envío es el mismo exista el correo o no, y por eso se muestra
 * como confirmación y no como resultado: decir «ese correo no está
 * registrado» convertiría este formulario en el oráculo que el login se cuida
 * de no ser.
 */

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
            title="Recupera tu acceso"
            description="Te enviamos un enlace para elegir una contraseña nueva. Dura una hora y sirve una sola vez."
            status={status}
            footer={
                <Link href={route('login')} className="link link-primary">
                    Volver al acceso
                </Link>
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

                <Button type="submit" block loading={processing} className="mt-2">
                    Enviarme el enlace
                </Button>
            </form>
        </AccessLayout>
    );
}
