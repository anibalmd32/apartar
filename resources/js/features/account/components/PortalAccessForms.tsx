import { Button } from '@/components/ui/button';
import { DocumentField } from '@/components/ui/document-field';
import { TextField } from '@/components/ui/text-field';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

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

export function CodeAccess({ initialCode = null }: { initialCode?: string | null }) {
    const { data, setData, post, processing, errors } = useForm<CodeForm>({
        document: '',
        code: initialCode ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.access.code'));
    };

    return (
        <form className="flex flex-col gap-1" onSubmit={submit} noValidate aria-label="Entrar con el código">
            <DocumentField
                label="Cédula o RIF"
                name="document"
                value={data.document}
                onChange={(value) => setData('document', value)}
                error={errors.document}
                autoFocus
                required
            />

            <TextField
                label="Código de apartado"
                name="code"
                value={data.code}
                onChange={(event) => setData('code', event.target.value)}
                error={errors.code}
                hint={initialCode ? 'Ya va puesto el del apartado que acabas de hacer.' : 'Está en el correo o en la pantalla de confirmación.'}
                autoComplete="off"
                placeholder="AP-7K3M9QRT"
                required
            />

            <Button type="submit" size="lg" block loading={processing} className="mt-2">
                {initialCode ? 'Continuar al pago' : 'Ver mi apartado'}
            </Button>
        </form>
    );
}

export function LinkAccess() {
    const { data, setData, post, processing, errors, reset } = useForm<LinkForm>({
        document: '',
        email: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.link.store'), { onSuccess: () => reset() });
    };

    return (
        <form className="flex flex-col gap-1" onSubmit={submit} noValidate aria-label="Recibir un enlace por correo">
            <DocumentField
                label="Cédula o RIF del apartado"
                name="link-document"
                value={data.document}
                onChange={(value) => setData('document', value)}
                error={errors.document}
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

            <Button type="submit" size="lg" block loading={processing} className="mt-2">
                Enviarme un enlace
            </Button>
        </form>
    );
}
