import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { type PortalAccessProps } from '@/types/portal';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Entrar al portal del cliente (`REQ-IDN-008`, `REQ-IDN-003`).
 *
 * Dos vías, y la primera es la que no deja a nadie fuera:
 *
 *  - **Cédula + código del apartado** (`REQ-IDN-008`): no pide correo ni
 *    cuenta de Google (RB-1). Cuando los datos no casan, el error es uno solo
 *    y va en el campo del código: no dice si la cédula tiene apartados ni si
 *    el código existe (RB-5). El código se acepta como se copie —minúsculas,
 *    con o sin guion, con o sin `AP-`— y lo normaliza el servidor (SP-7).
 *  - **Un enlace por correo** (`REQ-IDN-003`), para quien dejó uno. La
 *    respuesta es la misma coincidan o no los datos (RB-2), y llega como el
 *    aviso de la cabecera; quien no dejó correo no recibirá nada, y por eso la
 *    vía del código va primero (SP-4).
 */

interface CodeForm {
    [key: string]: string;
    document: string;
    code: string;
}

interface LinkForm {
    [key: string]: string;
    document: string;
    email: string;
}

export default function Access({ status }: PortalAccessProps) {
    return (
        <AccessLayout
            title="Consulta tu apartado"
            description="Con tu cédula y el código que te dimos al apartar."
            status={status}
            footer={
                <>¿No encuentras el código? Está en tu confirmación y en el correo, si dejaste uno. Si no, pídeselo al negocio donde apartaste.</>
            }
        >
            <CodeAccess />

            <div className="divider text-xs opacity-70">o, si dejaste tu correo</div>

            <LinkAccess />
        </AccessLayout>
    );
}

function CodeAccess() {
    const { data, setData, post, processing, errors } = useForm<CodeForm>({
        document: '',
        code: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.access.code'));
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={submit} noValidate aria-label="Entrar con el código">
            <TextField
                label="Cédula o RIF"
                name="document"
                value={data.document}
                onChange={(event) => setData('document', event.target.value)}
                error={errors.document}
                autoComplete="off"
                placeholder="V-12.345.678"
                autoFocus
                required
            />

            <TextField
                label="Código de apartado"
                name="code"
                value={data.code}
                onChange={(event) => setData('code', event.target.value)}
                error={errors.code}
                autoComplete="off"
                placeholder="AP-7K3M9QRT"
                required
            />

            <Button type="submit" block loading={processing} className="mt-2">
                Ver mi apartado
            </Button>
        </form>
    );
}

function LinkAccess() {
    const { data, setData, post, processing, errors, reset } = useForm<LinkForm>({
        document: '',
        email: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.link.store'), { onSuccess: () => reset() });
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={submit} noValidate aria-label="Recibir un enlace por correo">
            <TextField
                label="Cédula o RIF del apartado"
                name="link-document"
                value={data.document}
                onChange={(event) => setData('document', event.target.value)}
                error={errors.document}
                autoComplete="off"
                required
            />

            <TextField
                label="Correo que dejaste"
                name="email"
                type="email"
                value={data.email}
                onChange={(event) => setData('email', event.target.value)}
                error={errors.email}
                autoComplete="email"
                required
            />

            <Button type="submit" block variant="secondary" loading={processing} className="mt-2">
                Enviarme un enlace
            </Button>
        </form>
    );
}
