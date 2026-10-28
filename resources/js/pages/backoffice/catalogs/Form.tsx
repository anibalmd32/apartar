import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FileField } from '@/components/ui/file-field';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { CatalogProductSorter } from '@/features/catalogs/components/CatalogProductSorter';
import { CatalogPublishPanel } from '@/features/catalogs/components/CatalogPublishPanel';
import { CatalogVisibilityBadge } from '@/features/catalogs/components/CatalogVisibilityBadge';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CatalogFormValues, type CatalogProductOption, type CatalogPublishBlockers } from '@/types/catalog';
import { cilCheckAlt, cilExternalLink } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface CatalogFormPageProps {
    catalog: CatalogFormValues | null;
    products: CatalogProductOption[];
    availableProducts: CatalogProductOption[];
    suggestedProductIds?: number[];
    blockers: CatalogPublishBlockers;
    [key: string]: unknown;
}

const FORM_ID = 'catalog-form';

export default function CatalogForm({ catalog, products, availableProducts, suggestedProductIds = [], blockers }: CatalogFormPageProps) {
    const form = useForm({
        name: catalog?.name ?? '',
        slug: catalog?.slug ?? '',
        description: catalog?.description ?? '',
        cover: null as File | null,
    });

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();

        if (catalog === null) {
            form.post('/backoffice/catalogos');

            return;
        }

        form.transform((data) => ({ ...data, _method: 'put' }));
        form.post(`/backoffice/catalogos/${catalog.id}`);
    };

    const selling = catalog !== null && (catalog.visibility === 'listed' || catalog.visibility === 'unlisted');

    return (
        <BackofficeLayout
            title={catalog === null ? 'Nuevo catálogo' : catalog.name}
            back={{ href: '/backoffice/catalogos', label: 'Catálogos' }}
            badge={catalog === null ? undefined : <CatalogVisibilityBadge visibility={catalog.visibility} />}
            description={
                catalog?.updatedAt
                    ? `Última modificación: ${catalog.updatedAt}${catalog.updatedBy !== null ? `, por ${catalog.updatedBy}` : ''}`
                    : 'Una campaña con su propio enlace. Elige qué productos entran, en qué orden se ven y cuándo deja de estar disponible.'
            }
            actions={
                <>
                    {selling ? (
                        <a className="btn btn-ghost" href={catalog.shareUrl} target="_blank" rel="noreferrer">
                            <Icon icon={cilExternalLink} />
                            Vista previa
                        </a>
                    ) : null}
                    <Button type="submit" form={FORM_ID} loading={form.processing}>
                        <Icon icon={cilCheckAlt} />
                        {catalog === null ? 'Crear catálogo' : 'Guardar cambios'}
                    </Button>
                </>
            }
        >
            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:gap-6">
                <div className="flex flex-col gap-4 lg:gap-6">
                    <SectionCard title="Detalles">
                        <form id={FORM_ID} className="flex flex-col" onSubmit={onSubmit} noValidate>
                            <TextField
                                label="Nombre"
                                name="name"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                                error={form.errors.name}
                                hint="Es lo que verá quien reciba el enlace: «Ofertas de septiembre» se entiende, «Catálogo 3» no."
                                required
                            />

                            <TextField
                                label="Enlace"
                                name="slug"
                                prefix="/c/"
                                value={form.data.slug}
                                onChange={(event) => form.setData('slug', event.target.value)}
                                error={form.errors.slug}
                                disabled={catalog?.slugIsLocked ?? false}
                                hint={
                                    catalog?.slugIsLocked
                                        ? 'Este catálogo ya se publicó, así que su enlace no se puede cambiar: la URL ya circula. Despublicarlo tampoco lo libera.'
                                        : 'Puedes cambiarlo mientras sea borrador. En cuanto lo publiques queda fijo para siempre, porque el enlace empieza a circular. Si otro negocio ya tiene ese enlace, le añadimos un número y te lo mostramos aquí.'
                                }
                            />

                            <TextField
                                label="Descripción (opcional)"
                                name="description"
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                                error={form.errors.description}
                                hint="Una línea sobre de qué va la campaña."
                            />

                            <FileField
                                label="Portada"
                                name="cover"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) => form.setData('cover', event.target.files?.[0] ?? null)}
                                error={form.errors.cover}
                                hint="Opcional: si no pones ninguna, se usa la imagen del primer producto."
                                preview={
                                    catalog?.coverUrl ? (
                                        <img src={catalog.coverUrl} alt="Portada actual del catálogo" className="h-16 w-28 rounded-xl object-cover" />
                                    ) : undefined
                                }
                            />
                        </form>
                    </SectionCard>

                    {catalog === null ? (
                        <Alert tone="info">Crea el catálogo y podrás añadirle productos, ordenarlos y publicarlo.</Alert>
                    ) : (
                        <SectionCard title="Productos">
                            <CatalogProductSorter
                                catalogId={catalog.id}
                                products={products}
                                available={availableProducts}
                                suggested={suggestedProductIds}
                                isPublished={catalog.visibility !== 'draft'}
                            />
                        </SectionCard>
                    )}
                </div>

                {catalog === null ? null : (
                    <div className="flex flex-col gap-4 lg:gap-6">
                        <CatalogPublishPanel catalog={catalog} blockers={blockers} />
                    </div>
                )}
            </div>
        </BackofficeLayout>
    );
}
