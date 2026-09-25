import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FileField } from '@/components/ui/file-field';
import { TextField } from '@/components/ui/text-field';
import { CatalogProductSorter } from '@/features/catalogs/components/CatalogProductSorter';
import { CatalogPublishPanel } from '@/features/catalogs/components/CatalogPublishPanel';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CatalogFormValues, type CatalogProductOption, type CatalogPublishBlockers } from '@/types/catalog';
import { Link, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Alta y edición de un catálogo (`REQ-BOF-024` RB-1, RB-2, RB-4).
 *
 * Tres bloques y tres guardados distintos, que es el reparto del servidor
 * puesto en pantalla: **la identidad** del catálogo va por `POST`/`PUT`, **la
 * composición** por su `PATCH` con su propio botón (`REQ-CAT-023` RB-8), y
 * **la publicación** por `POST /publicar`. Un solo «Guardar» que lo mandara
 * todo escondería que publicar tiene seis requisitos y que reordenar no.
 *
 * ## El compositor solo existe al editar
 *
 * `catalog_product` necesita un `catalog_id`, y al crear todavía no lo hay.
 * Es el mismo orden que el producto sigue con su galería: se crea el borrador
 * y luego se completa, y crear redirige aquí.
 *
 * ## Y el enlace se explica **antes** de publicar
 *
 * RB-4: tras la primera publicación es inmutable, y después no hay vuelta
 * atrás. Así que mientras es borrador el campo está abierto con el aviso al
 * lado, y en cuanto se publica queda deshabilitado con el motivo. Quien manda
 * sigue siendo la acción; esto evita el `422` que se ve venir.
 */

interface CatalogFormPageProps {
    catalog: CatalogFormValues | null;
    products: CatalogProductOption[];
    availableProducts: CatalogProductOption[];
    blockers: CatalogPublishBlockers;
    [key: string]: unknown;
}

export default function CatalogForm({ catalog, products, availableProducts, blockers }: CatalogFormPageProps) {
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

        // El formulario lleva un archivo, y un `PUT` multipart no lo
        // interpretan ni PHP ni Laravel. El router resuelve `_method` antes de
        // emparejar, así que la ruta sigue siendo `PUT` — es lo mismo que hace
        // el formulario de identidad del negocio.
        form.transform((data) => ({ ...data, _method: 'put' }));
        form.post(`/backoffice/catalogos/${catalog.id}`);
    };

    return (
        <BackofficeLayout
            title={catalog === null ? 'Nuevo catálogo' : 'Editar catálogo'}
            description="Una campaña con su propio enlace. Elige qué productos entran, en qué orden se ven y cuándo deja de estar disponible."
        >
            <form className="card bg-base-100 shadow-sm" onSubmit={onSubmit} noValidate>
                <div className="card-body gap-3">
                    <h2 className="card-title text-base">Datos del catálogo</h2>

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
                        label="Descripción"
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                        error={form.errors.description}
                        hint="Opcional. Una línea sobre de qué va la campaña."
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
                                <img src={catalog.coverUrl} alt="Portada actual del catálogo" className="h-12 w-20 rounded object-cover" />
                            ) : undefined
                        }
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" loading={form.processing}>
                            {catalog === null ? 'Crear catálogo' : 'Guardar cambios'}
                        </Button>

                        <Link className="btn btn-ghost" href="/backoffice/catalogos">
                            Volver al listado
                        </Link>

                        {/* `REQ-CAT-023` SP-4 · dos personas componiendo el
                            mismo catálogo: gana el último que guarde, y la
                            marca —con su autor desde `T-038`— es lo que
                            permite darse cuenta. */}
                        {catalog?.updatedAt ? (
                            <p className="ml-auto self-center text-xs opacity-70">
                                Última modificación: {catalog.updatedAt}
                                {catalog.updatedBy !== null ? `, por ${catalog.updatedBy}` : null}
                            </p>
                        ) : null}
                    </div>
                </div>
            </form>

            {catalog === null ? (
                <Alert tone="info">Crea el catálogo y podrás añadirle productos, ordenarlos y publicarlo.</Alert>
            ) : (
                <>
                    <section className="card bg-base-100 shadow-sm">
                        <div className="card-body gap-3">
                            <h2 className="card-title text-base">Productos del catálogo</h2>

                            <CatalogProductSorter
                                catalogId={catalog.id}
                                products={products}
                                available={availableProducts}
                                isPublished={catalog.visibility !== 'draft'}
                            />
                        </div>
                    </section>

                    <CatalogPublishPanel catalog={catalog} blockers={blockers} />
                </>
            )}
        </BackofficeLayout>
    );
}
