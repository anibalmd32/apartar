import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import { suggestSlug } from '@/lib/slug';
import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

/**
 * Registro de un negocio (`REQ-ORG-001`).
 *
 * Primera pantalla propia del proyecto, así que fija el patrón: daisyUI
 * envuelto en `components/ui`, nada de `components/vendor` —shadcn heredado en
 * retirada— y móvil primero, que es por donde entra este público.
 *
 * El enlace se sugiere solo mientras se escribe el nombre y sigue siendo
 * editable (RB-2). Es un detalle pequeño con un efecto grande: hace que el
 * choque de nombres de SP-1 casi nunca ocurra, porque nadie acaba pidiendo
 * `tienda`.
 */

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

    // El enlace sigue al nombre hasta que alguien lo escribe a mano. Es un
    // estado y no un cálculo: «lo tocó» no se deduce del valor, y confundir
    // las dos cosas haría que borrar el campo reactivara la sugerencia.
    const [slugEdited, setSlugEdited] = useState(false);

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
        <main className="flex min-h-screen items-start justify-center p-4 sm:items-center">
            <Head title="Crear mi negocio" />

            <div className="card bg-base-100 w-full max-w-lg shadow-sm">
                <div className="card-body gap-4">
                    <header>
                        <h1 className="card-title text-xl">Crea tu negocio en Apartar</h1>
                        <p className="text-sm opacity-70">Empiezas en el plan Free, sin tarjeta y sin período de prueba que se acabe.</p>
                    </header>

                    {errors.organization_slug ? <Alert tone="warning">{errors.organization_slug}</Alert> : null}

                    <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
                        <TextField
                            label="Nombre del negocio"
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
                            hint="Lo compartes por WhatsApp. Puedes cambiarlo mientras no publiques un catálogo."
                            inputMode="url"
                            required
                        />

                        <div className="divider my-1 text-xs opacity-60">Tu cuenta</div>

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

                        <TextField
                            label="Contraseña"
                            name="owner_password"
                            type="password"
                            value={data.owner_password}
                            onChange={(event) => setData('owner_password', event.target.value)}
                            error={errors.owner_password}
                            hint="Doce caracteres o más, con mayúsculas, minúsculas y números."
                            autoComplete="new-password"
                            required
                        />

                        <TextField
                            label="Repite la contraseña"
                            name="owner_password_confirmation"
                            type="password"
                            value={data.owner_password_confirmation}
                            onChange={(event) => setData('owner_password_confirmation', event.target.value)}
                            autoComplete="new-password"
                            required
                        />

                        <Button type="submit" block loading={processing} className="mt-2">
                            Crear mi negocio
                        </Button>
                    </form>

                    <p className="text-center text-sm opacity-70">
                        ¿Ya tienes cuenta?{' '}
                        <Link href={route('login')} className="link link-primary">
                            Inicia sesión
                        </Link>
                    </p>
                </div>
            </div>
        </main>
    );
}
