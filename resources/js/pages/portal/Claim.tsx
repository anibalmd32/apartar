import { Button } from '@/components/ui/button';
import { DocumentField } from '@/components/ui/document-field';
import { Icon } from '@/components/ui/icon';
import { TextField } from '@/components/ui/text-field';
import { useAccountSession } from '@/features/account/hooks/useAccountSession';
import PortalLayout from '@/layouts/portal-layout';
import { type PortalClaimProps } from '@/types/portal';
import { cilArrowLeft, cilPlus } from '@coreui/icons';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ClaimForm {
    [key: string]: string;
    document: string;
    code: string;
}

export default function Claim({ storeUrl }: PortalClaimProps) {
    const { data, setData, post, processing, errors } = useForm<ClaimForm>({ document: '', code: '' });
    const { session } = useAccountSession();

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(storeUrl);
    };

    return (
        <PortalLayout title="Recupera tu historial" businessName="Mi cuenta" customerName={session?.fullName ?? ''}>
            <div className="flex w-full max-w-[560px] flex-col gap-4 lg:gap-[18px]">
                <Link
                    href={route('portal.dashboard')}
                    className="text-ink-3 hover:text-base-content flex items-center gap-1.5 self-start text-[13px] font-semibold"
                >
                    <Icon icon={cilArrowLeft} size={14} />
                    Volver a mis apartados
                </Link>

                <header className="flex flex-col gap-1.5">
                    <h1 className="text-[24px] leading-tight font-extrabold tracking-[-0.6px] lg:text-[30px]">Agrega un apartado a tu cuenta</h1>
                    <p className="text-ink-2 text-[15px] leading-normal">
                        Si ya habías apartado en algún negocio sin tu cuenta, escribe tu cédula y el código de ese apartado para verlo junto a los
                        demás.
                    </p>
                </header>

                <form
                    className="border-line bg-base-100 shadow-card flex flex-col gap-1 rounded-2xl border p-5"
                    onSubmit={submit}
                    noValidate
                    aria-label="Recuperar mi historial"
                >
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
                        hint="Está en tu confirmación y en el correo, si dejaste uno. Empieza por AP-."
                        autoComplete="off"
                        placeholder="AP-7K3M9QRT"
                        required
                    />

                    <Button type="submit" size="lg" block loading={processing} className="mt-2">
                        <Icon icon={cilPlus} />
                        Recuperar
                    </Button>
                </form>
            </div>
        </PortalLayout>
    );
}
