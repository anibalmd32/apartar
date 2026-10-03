import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { type PortalAccessProps } from '@/types/portal';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Entrar al portal con la cédula y el código del apartado (`REQ-IDN-008`).
 *
 * Es la vía que no deja a nadie fuera: no pide correo ni cuenta de Google
 * (RB-1). Pide **los dos datos** (RB-2), y cuando no casan el error es uno
 * solo y va en el campo del código: no dice si la cédula tiene apartados ni si
 * el código existe (RB-5), porque decirlo convertiría esta pantalla en un
 * buscador de quién compra dónde.
 *
 * El código se acepta como la persona lo copie —minúsculas, con o sin guion,
 * con o sin `AP-`—; lo normaliza el servidor (SP-7).
 */

interface AccessForm {
    [key: string]: string;
    document: string;
    code: string;
}

export default function Access({ status }: PortalAccessProps) {
    const { data, setData, post, processing, errors } = useForm<AccessForm>({
        document: '',
        code: '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.access.code'));
    };

    return (
        <AccessLayout
            title="Consulta tu apartado"
            description="Con tu cédula y el código que te dimos al apartar."
            status={status}
            footer={
                <>¿No encuentras el código? Está en tu confirmación y en el correo, si dejaste uno. Si no, pídeselo al negocio donde apartaste.</>
            }
        >
            <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
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
        </AccessLayout>
    );
}
