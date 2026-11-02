import { BackLink } from '@/components/ui/back-link';
import { Button } from '@/components/ui/button';
import { DocumentField } from '@/components/ui/document-field';
import { Icon } from '@/components/ui/icon';
import { TextField } from '@/components/ui/text-field';
import { useAccountSession } from '@/features/account/hooks/useAccountSession';
import { focusesOnDesktop } from '@/features/account/lib/desktop-focus';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalClaimProps } from '@/types/portal';
import { cilPlus } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ClaimForm {
    [key: string]: string;
    document: string;
    code: string;
}

export default function Claim({ storeUrl, document }: PortalClaimProps) {
    const { data, setData, post, processing, errors } = useForm<ClaimForm>({ document: document ?? '', code: '' });
    const { session } = useAccountSession();

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(storeUrl);
    };

    return (
        <PortalLayout title="Agrega un apartado a tu cuenta" businessName="Mi cuenta" customerName={session?.fullName ?? ''}>
            <div className="flex w-full max-w-[560px] flex-col gap-4 lg:gap-[18px]">
                <BackLink href={route('portal.dashboard')}>Volver a mis apartados</BackLink>

                <header className="flex flex-col gap-1.5">
                    <h1 className="tracking-heading text-[24px] leading-tight font-extrabold lg:text-[30px]">Agrega un apartado a tu cuenta</h1>
                    <p className="text-ink-2 text-[15px] leading-normal">
                        Si ya habías apartado en algún negocio sin tu cuenta, escribe tu cédula y el código de ese apartado para verlo junto a los
                        demás.
                    </p>
                </header>

                <form
                    className="border-line bg-base-100 shadow-card flex flex-col gap-1 rounded-2xl border p-5"
                    onSubmit={submit}
                    noValidate
                    aria-label="Agregar un apartado a mi cuenta"
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

                    <TextField
                        label="Código de apartado"
                        name="code"
                        value={data.code}
                        onChange={(event) => setData('code', event.target.value)}
                        error={errors.code}
                        hint="Está en tu confirmación y en el correo, si dejaste uno. Empieza por AP-."
                        autoComplete="off"
                        autoCapitalize="characters"
                        autoCorrect="off"
                        spellCheck={false}
                        placeholder="AP-7K3M9QRT"
                        required
                    />

                    <Button type="submit" size="lg" block loading={processing} className="mt-2">
                        <Icon icon={cilPlus} />
                        Agregar a mi cuenta
                    </Button>
                </form>
            </div>
        </PortalLayout>
    );
}
