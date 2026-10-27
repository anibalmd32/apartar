import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { SectionCard } from '@/components/ui/section-card';
import { OrganizationIdentityFields } from '@/features/marketing/components/OrganizationIdentityFields';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface FoundForm {
    [key: string]: string;
    organization_name: string;
    organization_slug: string;
}

export default function CreateOrganization() {
    const { data, setData, post, processing, errors } = useForm<FoundForm>({ organization_name: '', organization_slug: '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('organizations.store'));
    };

    return (
        <BackofficeLayout
            title="Crear mi negocio"
            description="Abres tu propio negocio con la misma cuenta y cambias entre ellos desde el menú de negocios."
        >
            <SectionCard title="Tu negocio" subtitle="Empiezas en el plan Gratis. Por ahora cada persona puede tener un solo negocio propio.">
                <form className="flex max-w-[440px] flex-col gap-4" onSubmit={submit} noValidate>
                    {errors.organization_slug ? <Alert tone="warning">{errors.organization_slug}</Alert> : null}

                    <OrganizationIdentityFields
                        value={{ organization_name: data.organization_name, organization_slug: data.organization_slug }}
                        onChange={(identity) => setData((previous) => ({ ...previous, ...identity }))}
                        errors={errors}
                        slugEndpoint={route('organizations.slug')}
                    />

                    <div className="flex justify-end">
                        <Button type="submit" loading={processing}>
                            Crear mi negocio
                        </Button>
                    </div>
                </form>
            </SectionCard>
        </BackofficeLayout>
    );
}
