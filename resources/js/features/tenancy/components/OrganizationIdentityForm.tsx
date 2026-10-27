import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { FileField } from '@/components/ui/file-field';
import { TextField } from '@/components/ui/text-field';
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

interface OrganizationIdentityFormProps {
    identity: OrganizationIdentity;
    brand: OrganizationBrand;
}

export function OrganizationIdentityForm({ identity, brand }: OrganizationIdentityFormProps) {
    const { data, setData, post, processing, errors } = useForm<IdentityForm>({
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

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/ajustes/identidad', { forceFormData: true, preserveScroll: true });
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <TextField
                    label="Nombre del negocio"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                    required
                />

                <TextField
                    label="Enlace de tu vitrina"
                    prefix="apartar.store/"
                    value={data.slug}
                    onChange={(event) => setData('slug', event.target.value)}
                    error={errors.slug}
                    hint="Cambiarlo rompe los enlaces que ya compartiste, así que se cierra en cuanto publiques un catálogo."
                    required
                />

                <TextField
                    label="RIF o cédula"
                    value={data.document}
                    onChange={(event) => setData('document', event.target.value)}
                    error={errors.document}
                    placeholder="J-41234567-8 o V-12345678"
                    hint="El RIF del negocio o, si vendes por tu cuenta y no tienes, tu cédula. Hace falta para tu primera factura de suscripción."
                />

                <TextField
                    label="Teléfono de contacto"
                    value={data.phone}
                    onChange={(event) => setData('phone', event.target.value)}
                    error={errors.phone}
                />

                <TextField
                    label="Zona horaria"
                    value={data.timezone}
                    onChange={(event) => setData('timezone', event.target.value)}
                    error={errors.timezone}
                    hint="Rige cómo se muestran tus fechas y a qué hora salen los avisos. No mueve vencimientos ya fijados."
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
                    hint="Una línea bajo tu nombre en la vitrina y el catálogo. Ej.: «Bolsos y calzado · Caracas»."
                    maxLength={80}
                />
                <TextField
                    label="Dirección"
                    value={data.address}
                    onChange={(event) => setData('address', event.target.value)}
                    error={errors.address}
                    hint="Opcional. Aparece en tu vitrina."
                    maxLength={160}
                />
            </div>
            <TextField
                label="Descripción del negocio"
                value={data.description}
                onChange={(event) => setData('description', event.target.value)}
                error={errors.description}
                hint={`${data.description.length} / 300 caracteres. Lo que cuentas de ti en tu vitrina.`}
                maxLength={300}
            />

            {brand.brandingEnabled ? null : (
                <Alert tone="info" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                    <span>
                        Tu plan todavía no aplica tu logo ni tu color: tu catálogo muestra el sello «Hecho con Apartar». Puedes configurarlos
                        igualmente —se guardan— y se activarán solos al subir de plan, sin volver a tocar nada.
                    </span>

                    <a className="btn btn-sm btn-info" href="/backoffice/suscripcion">
                        Ver planes
                    </a>
                </Alert>
            )}

            <FileField
                label="Logo"
                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                onChange={(event) => setData('logo', event.target.files?.[0] ?? null)}
                error={errors.logo}
                hint="PNG, JPG, WebP o SVG, hasta 2 MB."
                preview={
                    brand.logoUrl === null ? null : (
                        <img src={brand.logoUrl} alt={`Logo de ${brand.name}`} className="h-12 w-12 rounded object-contain" />
                    )
                }
            />

            <CheckboxField
                label="Mostrar mi vitrina pública"
                checked={data.storefront_enabled}
                onChange={(event) => setData('storefront_enabled', event.target.checked)}
                error={errors.storefront_enabled}
                hint="Con la vitrina encendida, cualquiera que abra tu enlace ve tus catálogos publicados."
            />

            <Button type="submit" loading={processing} className="self-start">
                Guardar identidad
            </Button>
        </form>
    );
}
