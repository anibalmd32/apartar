import { RichTextField } from '@/components/ui/rich-text-field';
import { SectionCard } from '@/components/ui/section-card';
import { TextField } from '@/components/ui/text-field';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { suggestSku } from '@/features/products/lib/sku';
import { type ProductFormValues } from '@/types/product';
import { useState } from 'react';

interface ProductIdentityFieldsProps extends ProductFieldsProps {
    product: ProductFormValues | null;
    skuSequence?: number;
}

const DESCRIPTION_MAX = 5000;

export function ProductIdentityFields({ data, setData, errors, product, skuSequence = 1 }: ProductIdentityFieldsProps) {
    const [skuEdited, setSkuEdited] = useState(product !== null);

    const changeName = (name: string) => {
        if (skuEdited) {
            setData('name', name);

            return;
        }

        setData((previous) => ({ ...previous, name, sku: suggestSku(name, skuSequence) }));
    };

    return (
        <SectionCard title="Información básica">
            <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <TextField
                        label="Nombre"
                        name="name"
                        value={data.name}
                        onChange={(event) => changeName(event.target.value)}
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
                    onChange={(event) => {
                        setSkuEdited(true);
                        setData('sku', event.target.value);
                    }}
                    error={errors.sku}
                    hint={
                        product === null && !skuEdited
                            ? 'Lo sugerimos a partir del nombre. Puedes cambiarlo: es tu código interno.'
                            : 'Tu código interno. Otro negocio puede usar el mismo sin que interfiera.'
                    }
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

                <RichTextField
                    label="Descripción"
                    className="sm:col-span-2"
                    value={data.description}
                    onChange={(html) => setData('description', html)}
                    error={errors.description}
                    hint="Puede quedar vacía mientras el producto sea un borrador; publicarlo la exige."
                    maxLength={DESCRIPTION_MAX}
                />
            </div>
        </SectionCard>
    );
}
