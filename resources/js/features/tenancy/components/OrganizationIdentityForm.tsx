import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { DocumentField } from '@/components/ui/document-field';
import { FileField } from '@/components/ui/file-field';
import { FormActionBar } from '@/components/ui/form-action-bar';
import { PhoneField } from '@/components/ui/phone-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type SettingsSectionLink, useReportFormState } from '@/features/tenancy/components/SettingsSaveBar';
import { timezoneOptions } from '@/features/tenancy/lib/timezones';
import { bo } from '@/lib/surfaces';
import { type OrganizationBrand, type OrganizationIdentity } from '@/types/backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface IdentityForm {
    [key: string]: string | boolean | File | null;
    _method: 'put';
    name: string;
    slug: string;
    document: string;
    phone: string;
    timezone: string;
    brand_color: string;
    storefront_enabled: boolean;
    tagline: string;
    description: string;
    address: string;
    logo: File | null;
}

interface OrganizationIdentityFormProps extends SettingsSectionLink {
    identity: OrganizationIdentity;
    brand: OrganizationBrand;
}

export function OrganizationIdentityForm({ identity, brand, formId, onStateChange, savesFromBar }: OrganizationIdentityFormProps) {
    const { data, setData, setDefaults, post, processing, errors, isDirty } = useForm<IdentityForm>({
        _method: 'put',
        name: identity.name,
        slug: identity.slug,
        document: identity.document ?? '',
        phone: identity.phone ?? '',
        timezone: identity.timezone,
        brand_color: identity.brandColor ?? '#1f6feb',
        storefront_enabled: identity.storefrontEnabled,
        tagline: identity.tagline ?? '',
        description: identity.description ?? '',
        address: identity.address ?? '',
        logo: null,
    });

    useReportFormState(onStateChange, isDirty, processing);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post(bo('/ajustes/identidad'), { forceFormData: true, preserveScroll: true, onSuccess: () => setDefaults() });
    };

    return (
        <form id={formId} className="flex flex-col gap-2" onSubmit={submit} noValidate>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <TextField
                    label="Nombre del negocio"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                    required
                />

                <TextField
                    label="Enlace de tu tienda"
                    prefix="apartar.app/"
                    value={data.slug}
                    onChange={(event) => setData('slug', event.target.value)}
                    error={errors.slug}
                    hint="Cambiarlo rompe los enlaces que ya compartiste, así que se cierra en cuanto publiques un catálogo."
                    required
                />

                <DocumentField
                    label="RIF o cédula"
                    value={data.document}
                    onChange={(value) => setData('document', value)}
                    error={errors.document}
                    placeholder="412345678"
                    hint="El RIF del negocio o, si vendes por tu cuenta y no tienes, tu cédula. Hace falta para tu primera factura de suscripción."
                />

                <PhoneField label="Teléfono de contacto" value={data.phone} onChange={(value) => setData('phone', value)} error={errors.phone} />

                <SelectField
                    label="Zona horaria"
                    options={timezoneOptions(data.timezone)}
                    value={data.timezone}
                    onChange={(event) => setData('timezone', event.target.value)}
                    error={errors.timezone}
                    hint="Rige tus fechas y la hora de los avisos. No mueve vencimientos ya fijados."
                />

                <TextField
                    label="Color de marca"
                    type="color"
                    value={data.brand_color}
                    onChange={(event) => setData('brand_color', event.target.value)}
                    error={errors.brand_color}
                />
            </div>

            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <TextField
                    label="Eslogan"
                    value={data.tagline}
                    onChange={(event) => setData('tagline', event.target.value)}
                    error={errors.tagline}
                    hint="Una línea bajo tu nombre en la tienda y el catálogo. Ej.: «Bolsos y calzado · Caracas»."
                    maxLength={80}
                />
                <TextField
                    label="Dirección"
                    value={data.address}
                    onChange={(event) => setData('address', event.target.value)}
                    error={errors.address}
                    hint="Opcional. Aparece en tu tienda."
                    maxLength={160}
                />
            </div>
            <TextField
                label="Descripción del negocio"
                value={data.description}
                onChange={(event) => setData('description', event.target.value)}
                error={errors.description}
                hint={`${data.description.length} / 300 caracteres. Lo que cuentas de ti en tu tienda.`}
                maxLength={300}
            />

            {brand.brandingEnabled ? null : (
                <Alert tone="info" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                    <span>
                        Tu plan todavía no aplica tu logo ni tu color: tu catálogo muestra el sello «Hecho con Apartar». Puedes configurarlos
                        igualmente —se guardan— y se activarán solos al subir de plan, sin volver a tocar nada.
                    </span>

                    <a className="btn btn-sm btn-info" href={bo('/suscripcion')}>
                        Ver planes
                    </a>
                </Alert>
            )}

            <FileField
                label="Logo"
                accept="image/png,image/jpeg,image/webp"
                onChange={(event) => setData('logo', event.target.files?.[0] ?? null)}
                error={errors.logo}
                hint="PNG, JPG o WebP, hasta 2 MB."
                preview={
                    brand.logoUrl === null ? null : (
                        <img src={brand.logoUrl} alt={`Logo de ${brand.name}`} className="h-12 w-12 rounded object-contain" />
                    )
                }
            />

            <CheckboxField
                label="Mostrar mi tienda pública"
                checked={data.storefront_enabled}
                onChange={(event) => setData('storefront_enabled', event.target.checked)}
                error={errors.storefront_enabled}
                hint="Con la tienda encendida, cualquiera que abra tu enlace ve tus catálogos publicados."
            />

            <FormActionBar sticky={false} className={savesFromBar ? 'max-lg:hidden' : undefined}>
                <Button type="submit" loading={processing}>
                    Guardar identidad
                </Button>
            </FormActionBar>
        </form>
    );
}
