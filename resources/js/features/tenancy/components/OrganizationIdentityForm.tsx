import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { FileField } from '@/components/ui/file-field';
import { TextField } from '@/components/ui/text-field';
import { type OrganizationBrand, type OrganizationIdentity } from '@/types/backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * La identidad del negocio: nombre, enlace, contacto, zona y marca
 * (`REQ-ORG-006`).
 *
 * **El branding se guarda siempre, aunque el plan no lo aplique** (RB-5b,
 * SP-3). De ahí que los controles de logo y color **no estén deshabilitados ni
 * ocultos** cuando `brandingEnabled` es `false`: lo que hay es un aviso al
 * lado explicando que se guardan y no se aplican, y que al subir de plan se
 * activan sin volver a configurarlos.
 *
 * Es la lectura correcta de `REQ-SUB-004` RB-7 —el control se muestra con su
 * motivo, nunca oculto— combinada con RB-5b: aquí la función de plan no
 * gobierna **escribir** el dato, solo **aplicarlo**. Deshabilitar el campo
 * haría que subir de plan costara rellenar el formulario otra vez, que es
 * justo lo que SP-3 prohíbe.
 *
 * El enlace se avisa aparte: es lo único de esta pantalla que puede quedar
 * cerrado para siempre (SP-5).
 */

interface IdentityForm {
    [key: string]: string | boolean | File | null;
    /**
     * El formulario lleva un archivo, así que va por `POST` con el método
     * suplantado: un `PUT` multipart no lo interpretan ni PHP ni Laravel. Es
     * la vía que documenta Inertia, y el router de Laravel resuelve `_method`
     * **antes** de emparejar, así que la ruta sigue siendo `PUT`.
     */
    _method: 'put';
    name: string;
    slug: string;
    document: string;
    phone: string;
    timezone: string;
    brand_color: string;
    storefront_enabled: boolean;
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
        logo: null,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        post('/backoffice/ajustes/identidad', { forceFormData: true, preserveScroll: true });
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
            <div className="grid gap-2 sm:grid-cols-2">
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
                    label="RIF"
                    value={data.document}
                    onChange={(event) => setData('document', event.target.value)}
                    error={errors.document}
                    hint="Hace falta para emitir tu primera factura de suscripción."
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
