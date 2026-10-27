import { BrandMark } from '@/components/shell/brand';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { IconTextField } from '@/components/ui/icon-text-field';
import { TextField } from '@/components/ui/text-field';
import { RegisterBrandPanel } from '@/features/marketing/components/RegisterBrandPanel';
import { useSlugAvailability } from '@/features/marketing/hooks/useSlugAvailability';
import { PasswordStrength } from '@/layouts/auth/password-strength';
import { suggestSlug } from '@/lib/slug';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

interface RegisterForm {
    [key: string]: string;
    organization_name: string;
    organization_slug: string;
    owner_name: string;
    owner_email: string;
    owner_password: string;
    owner_password_confirmation: string;
}

const EMPTY_FORM: RegisterForm = {
    organization_name: '',
    organization_slug: '',
    owner_name: '',
    owner_email: '',
    owner_password: '',
    owner_password_confirmation: '',
};

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({ ...EMPTY_FORM });

    const [slugEdited, setSlugEdited] = useState(false);
    const availability = useSlugAvailability(data.organization_slug);

    const changeName = (name: string) => {
        setData((previous) => ({
            ...previous,
            organization_name: name,
            organization_slug: suggestSlug(name, previous.organization_slug, slugEdited),
        }));
    };

    const changeSlug = (slug: string) => {
        setSlugEdited(true);
        setData('organization_slug', slug);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('register'), {
            onFinish: () => reset('owner_password', 'owner_password_confirmation'),
        });
    };

    return (
        <main className="bg-base-200 flex min-h-screen">
            <Head title="Crear mi negocio" />

            <RegisterBrandPanel />

            <div className="flex flex-1 flex-col">
                <header className="flex h-[60px] items-center justify-between px-4 lg:hidden">
                    <Link href={route('home')} className="inline-flex items-center gap-2.5" aria-label="Apartar, inicio">
                        <BrandMark />
                        <span className="text-lg font-extrabold tracking-[-0.6px]">apartar</span>
                    </Link>
                    <Link href={route('login')} className="btn btn-ghost btn-sm">
                        Entrar
                    </Link>
                </header>

                <div className="flex flex-1 items-start justify-center px-4 pt-4 pb-10 lg:items-center lg:p-10">
                    <div className="flex w-full max-w-[440px] flex-col gap-4">
                        <header className="flex flex-col gap-4">
                            <h1 className="text-[28px] font-extrabold tracking-[-0.03em] lg:text-[32px]">Crea tu cuenta</h1>
                            <p className="text-ink-2 text-[15px]">Empiezas en el plan Gratis, sin tarjeta y sin período de prueba que se acabe.</p>
                        </header>

                        {errors.organization_slug ? <Alert tone="warning">{errors.organization_slug}</Alert> : null}

                        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
                            <TextField
                                label="Nombre de tu negocio"
                                name="organization_name"
                                value={data.organization_name}
                                onChange={(event) => changeName(event.target.value)}
                                error={errors.organization_name}
                                autoComplete="organization"
                                autoFocus
                                required
                            />

                            <TextField
                                label="Enlace de tu catálogo"
                                name="organization_slug"
                                prefix="apartar.store/"
                                value={data.organization_slug}
                                onChange={(event) => changeSlug(event.target.value)}
                                error={errors.organization_slug}
                                hint={
                                    availability.state === 'free' ? (
                                        <span className="text-success font-semibold">¡Disponible!</span>
                                    ) : availability.state === 'taken' ? (
                                        <span className="text-warning">
                                            Ya está en uso.
                                            {availability.suggestion !== null ? (
                                                <>
                                                    {' '}
                                                    <button
                                                        type="button"
                                                        className="text-primary-ink cursor-pointer font-semibold underline"
                                                        onClick={() => changeSlug(availability.suggestion ?? '')}
                                                    >
                                                        Usar {availability.suggestion}
                                                    </button>
                                                </>
                                            ) : null}
                                        </span>
                                    ) : (
                                        'Lo compartes por WhatsApp. Puedes cambiarlo mientras no publiques un catálogo.'
                                    )
                                }
                                inputMode="url"
                                required
                            />

                            <TextField
                                label="Tu nombre"
                                name="owner_name"
                                value={data.owner_name}
                                onChange={(event) => setData('owner_name', event.target.value)}
                                error={errors.owner_name}
                                autoComplete="name"
                                required
                            />

                            <TextField
                                label="Correo"
                                name="owner_email"
                                type="email"
                                value={data.owner_email}
                                onChange={(event) => setData('owner_email', event.target.value)}
                                error={errors.owner_email}
                                autoComplete="email"
                                required
                            />

                            <IconTextField
                                label="Contraseña"
                                name="owner_password"
                                type="password"
                                revealable
                                value={data.owner_password}
                                onChange={(event) => setData('owner_password', event.target.value)}
                                error={errors.owner_password}
                                describedBy="owner-password-strength"
                                autoComplete="new-password"
                                required
                            />

                            <PasswordStrength id="owner-password-strength" value={data.owner_password} />

                            <TextField
                                label="Repite la contraseña"
                                name="owner_password_confirmation"
                                type="password"
                                value={data.owner_password_confirmation}
                                onChange={(event) => setData('owner_password_confirmation', event.target.value)}
                                autoComplete="new-password"
                                required
                            />

                            <Button type="submit" block loading={processing} className="h-12 text-[15px]">
                                Crear mi cuenta gratis
                            </Button>
                        </form>

                        <p className="text-ink-2 text-center text-sm">
                            ¿Ya tienes cuenta?{' '}
                            <Link href={route('login')} className="link link-primary link-hover ml-1 font-bold">
                                Entrar
                            </Link>
                        </p>
                    </div>
                </div>
            </div>
        </main>
    );
}
