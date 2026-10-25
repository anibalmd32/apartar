import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { type SharedData } from '@/types';
import { cilEnvelopeLetter, cilReload } from '@coreui/icons';
import { Link, useForm, usePage } from '@inertiajs/react';
import { type FormEventHandler, useEffect, useState } from 'react';

const RESEND_AFTER = 60;

function useCountdown(restart: number): number {
    const [left, setLeft] = useState(RESEND_AFTER);

    useEffect(() => {
        setLeft(RESEND_AFTER);
        const timer = window.setInterval(() => setLeft((current) => Math.max(0, current - 1)), 1000);

        return () => window.clearInterval(timer);
    }, [restart]);

    return left;
}

export default function VerifyEmail({ status }: { status?: string }) {
    const { auth } = usePage<SharedData>().props;
    const { post, processing } = useForm({});
    const [sent, setSent] = useState(0);
    const left = useCountdown(sent);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'), { onSuccess: () => setSent((count) => count + 1) });
    };

    const email = auth.user?.email;

    return (
        <AccessLayout
            title="Confirma tu correo"
            description={
                <>Enviamos un enlace {email ? `a ${email}` : 'a tu correo'}. Ábrelo para activar los avisos por correo y proteger tu cuenta.</>
            }
            icon={cilEnvelopeLetter}
            centered
            status={status === 'verification-link-sent' ? 'Te enviamos un enlace nuevo al correo con el que te registraste.' : null}
            footer={
                <Link href={route('logout')} method="post" as="button" className="link link-primary link-hover cursor-pointer font-semibold">
                    Salir
                </Link>
            }
        >
            <form onSubmit={submit} className="flex flex-col gap-4 text-center">
                <Button type="submit" variant="secondary" block loading={processing} disabled={left > 0} className="h-12 text-[15px]">
                    {processing ? null : <Icon icon={cilReload} />}
                    Reenviar correo
                </Button>

                <p className="text-ink-3 text-xs">¿No te llega? Revisa spam o promociones.{left > 0 ? ` Puedes reenviarlo en ${left} s.` : null}</p>
            </form>
        </AccessLayout>
    );
}
