import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { cn } from '@/lib/utils';
import { type ProductFormValues } from '@/types/product';
import { useId } from 'react';

interface ProductIdentityFieldsProps extends ProductFieldsProps {
    product: ProductFormValues | null;
}

const DESCRIPTION_MAX = 5000;

export function ProductIdentityFields({ data, setData, errors, product }: ProductIdentityFieldsProps) {
    const descriptionId = useId();
    const hintId = `${descriptionId}-hint`;
    const errorId = `${descriptionId}-error`;

    return (
        <SectionCard title="Información básica">
            <div className="grid gap-x-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
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
                </div>

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

                <fieldset className="fieldset w-full sm:col-span-2">
                    <label className="fieldset-legend" htmlFor={descriptionId}>
                        Descripción
                    </label>
                    <textarea
                        id={descriptionId}
                        name="description"
                        rows={4}
                        className={cn('textarea w-full', errors.description && 'textarea-error')}
                        value={data.description}
                        onChange={(event) => setData('description', event.target.value)}
                        maxLength={DESCRIPTION_MAX}
                        aria-invalid={errors.description ? true : undefined}
                        aria-describedby={[hintId, errors.description ? errorId : null].filter(Boolean).join(' ')}
                    />
                    <p id={hintId} className="label flex justify-between gap-3 text-xs whitespace-normal">
                        <span>Puede quedar vacía mientras el producto sea un borrador; publicarlo la exige.</span>
                        <span className="shrink-0 tabular-nums">
                            {data.description.length} / {DESCRIPTION_MAX}
                        </span>
                    </p>
                    {errors.description ? (
                        <p id={errorId} role="alert" className="label text-error text-xs whitespace-normal">
                            {errors.description}
                        </p>
                    ) : null}
                </fieldset>
            </div>
        </SectionCard>
    );
}
