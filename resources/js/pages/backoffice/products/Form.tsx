import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ProductIdentityFields } from '@/features/products/components/ProductIdentityFields';
import { ProductPricingFields } from '@/features/products/components/ProductPricingFields';
import { ProductTaxonomyFields } from '@/features/products/components/ProductTaxonomyFields';
import { SpecificationBuilder } from '@/features/products/components/SpecificationBuilder';
import { useProductForm } from '@/features/products/hooks/useProductForm';
import { useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type FinancingPlanView, type ProductDefaults, type ProductFormValues, type TaxonomyOption } from '@/types/product';
import { Link } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Alta y edición de un producto (`REQ-BOF-020`).
 *
 * Una sola pantalla para las dos cosas, como el formulario de cuenta de cobro:
 * los campos, sus reglas y su previsualización son idénticos y lo único que
 * cambia es a dónde se envía.
 *
 * Los tres bloques viven en `features/products/components` y no aquí por una
 * razón práctica: esta pantalla va a crecer dos veces más —la ficha técnica es
 * `T-028` y las imágenes `T-029`— y un archivo de cuatrocientas líneas es
 * donde se pierde de vista qué campo gobierna qué regla.
 *
 * El producto **nace en borrador** y publicarlo es otra pantalla: la
 * completitud de RB-5 —imagen, categoría, descripción y condiciones válidas—
 * se comprueba entera en la transición, que escribe `T-030`. Media validación
 * aquí sería la que se queda corta y publica de más sin que nada falle.
 */

interface ProductFormPageProps {
    product: ProductFormValues | null;
    defaults: ProductDefaults;
    categories: TaxonomyOption[];
    tags: TaxonomyOption[];
    plan: FinancingPlanView | null;
    specificationGroups: string[];
    [key: string]: unknown;
}

export default function ProductForm({ product, defaults, categories, tags, plan, specificationGroups }: ProductFormPageProps) {
    const { data, setData, errors, processing, submit } = useProductForm(defaults, product);

    const sheet = useSpecificationRows(product);

    const fields = { data, setData, errors };

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();

        // La ficha va con el producto, en la misma operación (`REQ-BOF-021`
        // RB-8): se adjunta al enviar porque su orden lo gobierna
        // `useSpecificationRows` y no el estado del formulario.
        submit(sheet.payload());
    };

    return (
        <BackofficeLayout
            title={product === null ? 'Nuevo producto' : 'Editar producto'}
            description="Lo que vendes y con qué condiciones lo financias. Tus clientes verán estas cifras tal como quedan aquí."
        >
            {product?.status === 'archived' ? (
                <Alert tone="warning">
                    Este producto está archivado: no se muestra en tus catálogos, pero conserva su sitio en ellos y su historial.
                </Alert>
            ) : null}

            <form className="flex flex-col gap-4" onSubmit={onSubmit} noValidate>
                <ProductIdentityFields {...fields} product={product} />

                <ProductPricingFields {...fields} plan={plan} />

                <ProductTaxonomyFields {...fields} categories={categories} tags={tags} />

                <SpecificationBuilder rows={sheet} groups={specificationGroups} errors={errors} />

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" loading={processing}>
                        {product === null ? 'Crear producto' : 'Guardar cambios'}
                    </Button>

                    <Link className="btn btn-ghost" href="/backoffice/productos">
                        Volver al listado
                    </Link>

                    {/* SP-7 · dos personas editando el mismo producto: gana el
                        último, y la marca de última modificación es lo que
                        permite darse cuenta. */}
                    {product?.updatedAt ? (
                        <p className="ml-auto self-center text-xs opacity-70">
                            Última modificación: {new Date(product.updatedAt).toLocaleString('es-VE')}
                        </p>
                    ) : null}
                </div>
            </form>
        </BackofficeLayout>
    );
}
