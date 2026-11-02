import { Button } from '@/components/ui/button';
import { DocumentField } from '@/components/ui/document-field';
import { focusesOnDesktop } from '@/features/account/lib/desktop-focus';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalDocumentProps } from '@/types/portal';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

export default function Document({ mode, customerName, businessName, typed, storeUrl }: PortalDocumentProps) {
    const { data, setData, post, processing, errors } = useForm<{ document: string }>({ document: typed });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(storeUrl);
    };

    return (
        <PortalLayout title="Tu cédula" businessName={businessName} customerName={customerName}>
            <div className="flex w-full max-w-[560px] flex-col gap-4 lg:gap-[18px]">
                <header className="flex flex-col gap-1.5">
                    <h1 className="tracking-heading text-[24px] leading-tight font-extrabold lg:text-[30px]">Antes de seguir, tu cédula</h1>
                    <p className="text-ink-2 text-[15px] leading-normal">
                        {mode === 'account'
                            ? 'Escríbela una sola vez. Con ella te avisamos si algún negocio tiene apartados a tu nombre que aún no están en tu cuenta; para agregarlos te pediremos el código de uno de ellos.'
                            : `${businessName} cargó tu apartado sin tu cédula. Escríbela una sola vez: la próxima vez entrarás con ella y tu código, y nadie más podrá ver tus apartados.`}
                    </p>
                </header>

                <form
                    className="border-line bg-base-100 shadow-card flex flex-col gap-1 rounded-2xl border p-5"
                    onSubmit={submit}
                    noValidate
                    aria-label="Guardar mi cédula"
                >
                    <DocumentField
                        label="Cédula o RIF"
                        name="document"
                        value={data.document}
                        onChange={(value) => setData('document', value)}
                        error={errors.document}
                        autoFocus={focusesOnDesktop()}
                        required
                    />

                    <Button type="submit" size="lg" block loading={processing} disabled={data.document === ''} className="mt-2">
                        Guardar y continuar
                    </Button>
                </form>
            </div>
        </PortalLayout>
    );
}
