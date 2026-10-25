import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import AccessLayout from '@/layouts/backoffice/access-layout';
import { type PortalLoginLinkProps } from '@/types/portal';
import { cilEnvelopeOpen } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function LoginLink({ token, preview }: PortalLoginLinkProps) {
    const { data, setData, post, processing } = useForm<{ remember: boolean }>({ remember: true });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(route('portal.link.consume', { token }));
    };

    return (
        <AccessLayout
            title={preview ? 'Confirma que eres tú' : 'Entra a tus apartados'}
            description={
                preview
                    ? `Vas a entrar a tus apartados en ${preview.businessName}${preview.document ? ` con la cédula ${preview.document}` : ''}.`
                    : 'Pulsa para entrar con el enlace que te enviamos.'
            }
            icon={cilEnvelopeOpen}
            centered
        >
            <form onSubmit={submit} className="flex flex-col items-center gap-3">
                <Button type="submit" size="lg" block loading={processing}>
                    Entrar a Mi cuenta
                </Button>
                <CheckboxField
                    label="Recordarme en este dispositivo por 30 días"
                    checked={data.remember}
                    onChange={(event) => setData('remember', event.target.checked)}
                />
            </form>
        </AccessLayout>
    );
}
