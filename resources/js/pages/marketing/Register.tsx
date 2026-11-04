import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { IconTextField } from '@/components/ui/icon-text-field';
import { TextField } from '@/components/ui/text-field';
import { BoardBrand } from '@/features/marketing/board/BoardBrand';
import { BoardFrame } from '@/features/marketing/board/BoardFrame';
import { OrganizationIdentityFields } from '@/features/marketing/components/OrganizationIdentityFields';
import { RegisterBrandPanel } from '@/features/marketing/components/RegisterBrandPanel';
import { SiteFooter } from '@/features/marketing/components/SiteFooter';
import { PasswordStrength } from '@/layouts/auth/password-strength';
import { type RegisterProps } from '@/types/marketing';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface RegisterForm {
    [key: string]: string;
    organization_name: string;
    organization_slug: string;
    owner_name: string;
    owner_email: string;
    owner_password: string;
    owner_password_confirmation: string;
    plan: string;
}

const EMPTY_FORM: RegisterForm = {
    organization_name: '',
    organization_slug: '',
    owner_name: '',
    owner_email: '',
    owner_password: '',
    owner_password_confirmation: '',
    plan: '',
};

export default function Register({ plan = null }: RegisterProps) {
    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({ ...EMPTY_FORM, plan: plan?.code ?? '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('register'), {
            onError: (failed) => {
                if (failed.owner_password !== undefined) {
                    reset('owner_password', 'owner_password_confirmation');
                }
            },
        });
    };

    const header = (
        <header className="mx-auto flex h-16 w-full max-w-[1440px] items-center justify-between gap-4 px-4 sm:px-6 lg:h-[84px] lg:px-12">
            <Link href={route('home')} className="touch:min-h-11 flex items-center" aria-label="Apartar, inicio">
                <BoardBrand />
            </Link>
            <p className="text-pz-ink-2 flex items-center gap-2 text-[0.9375rem]">
                <ThemeToggle className="text-pz-ink hover:bg-pz-ghost-ink" />
                <span className="max-sm:hidden">¿Ya tienes cuenta?</span>
                <Link href={route('login')} className="pz-btn pz-btn-line touch:min-h-11 min-h-10 px-4">
                    Entrar
                </Link>
            </p>
        </header>
    );

    return (
        <>
            <Head title="Crear mi negocio" />
            <BoardFrame header={header} footer={<SiteFooter />}>
                <div className="mx-auto grid max-w-[1280px] gap-10 px-4 pt-4 pb-16 sm:px-6 lg:grid-cols-[1fr_minmax(0,460px)] lg:gap-20 lg:px-12 lg:pt-10 lg:pb-24">
                    <div className="flex flex-col gap-6 lg:order-2">
                        <header className="flex flex-col gap-3">
                            <h1 className="font-vinyl text-[3rem] sm:text-[4rem]">Crea tu cuenta</h1>
                            <p className="text-pz-ink-2 text-[1.0625rem] leading-relaxed">
                                {plan === null
                                    ? 'Empiezas en el plan Gratis, sin tarjeta y sin período de prueba que se acabe.'
                                    : `Tu cuenta empieza en el plan Gratis, sin tarjeta. Después te llevamos a Suscripción para pasar a ${plan.name} cuando quieras.`}
                            </p>
                        </header>
                        <RegisterBrandPanel className="lg:hidden" />

                        {errors.organization_slug ? <Alert tone="warning">{errors.organization_slug}</Alert> : null}

                        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
                            <OrganizationIdentityFields
                                value={{ organization_name: data.organization_name, organization_slug: data.organization_slug }}
                                onChange={(identity) => setData((previous) => ({ ...previous, ...identity }))}
                                errors={errors}
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

                            <p className="text-pz-ink-2 text-[0.9375rem] leading-normal">
                                Al crear tu cuenta aceptas los{' '}
                                <a
                                    className="text-pz-blue font-semibold underline"
                                    href={route('legal.terms')}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Términos de servicio
                                </a>{' '}
                                y la{' '}
                                <a
                                    className="text-pz-blue font-semibold underline"
                                    href={route('legal.privacy')}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Política de privacidad
                                </a>{' '}
                                de Apartar.
                            </p>

                            <Button type="submit" block loading={processing}>
                                Crear mi cuenta gratis
                            </Button>
                        </form>
                    </div>
                    <RegisterBrandPanel example className="max-lg:hidden lg:order-1 lg:pt-6" />
                </div>
            </BoardFrame>
        </>
    );
}
