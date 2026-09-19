import { TextField } from '@/components/ui/text-field';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type ProductFormValues } from '@/types/product';

/**
 * Qué se vende: nombre, SKU, enlace y descripción (`REQ-BOF-020` RB-3).
 *
 * ## El enlace solo aparece al editar, y se bloquea al publicar
 *
 * Al crear no existe el campo: el `slug` se autogenera del nombre (RB-3), y
 * ofrecerlo daría una tercera forma de fijarlo que nadie pidió. Al editar
 * existe, y se deshabilita en cuanto el producto está publicado (SP-5) porque
 * su URL ya circuló.
 *
 * Deshabilitarlo **no es la protección** —esa la pone `UpsertProductAction`,
 * que es quien mira el estado en la base— sino la cortesía de no dejar que
 * alguien escriba algo para recibir un `422` al guardar.
 *
 * El SKU es único por organización y no en la plataforma: dos negocios pueden
 * vender el mismo modelo con el mismo código de fábrica, y la pista lo dice
 * porque es lo contrario de lo que la gente espera de un «código único».
 */

interface ProductIdentityFieldsProps extends ProductFieldsProps {
    product: ProductFormValues | null;
}

export function ProductIdentityFields({ data, setData, errors, product }: ProductIdentityFieldsProps) {
    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body grid gap-2 sm:grid-cols-2">
                <h2 className="card-title text-base sm:col-span-2">Qué vendes</h2>

                <TextField
                    label="Nombre"
                    name="name"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                    maxLength={200}
                    autoComplete="off"
                    required
                />

                <TextField
                    label="SKU"
                    name="sku"
                    value={data.sku}
                    onChange={(event) => setData('sku', event.target.value)}
                    error={errors.sku}
                    hint="Tu código interno. Otro negocio puede usar el mismo sin que interfiera."
                    maxLength={64}
                    autoComplete="off"
                    required
                />

                {product === null ? null : (
                    <TextField
                        label="Enlace"
                        name="slug"
                        value={data.slug}
                        onChange={(event) => setData('slug', event.target.value)}
                        error={errors.slug}
                        disabled={product.slugIsLocked}
                        hint={
                            product.slugIsLocked
                                ? 'Está publicado: su enlace ya circuló y cambiarlo rompería lo que compartiste. El nombre sí lo puedes cambiar.'
                                : 'Se generó del nombre. Puedes cambiarlo mientras no esté publicado.'
                        }
                        maxLength={200}
                        autoComplete="off"
                    />
                )}

                <div className="sm:col-span-2">
                    <TextField
                        label="Descripción"
                        name="description"
                        value={data.description}
                        onChange={(event) => setData('description', event.target.value)}
                        error={errors.description}
                        hint="Puede quedar vacía mientras el producto sea un borrador; publicarlo la exige."
                        maxLength={5000}
                        autoComplete="off"
                    />
                </div>
            </div>
        </section>
    );
}
