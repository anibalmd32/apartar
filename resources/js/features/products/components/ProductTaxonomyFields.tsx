import { Chip } from '@/components/ui/chip';
import { SectionCard } from '@/components/ui/section-card';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type TaxonomyOption } from '@/types/product';
import { useId } from 'react';

interface ProductTaxonomyFieldsProps extends ProductFieldsProps {
    categories: readonly TaxonomyOption[];
    tags: readonly TaxonomyOption[];
}

export function ProductTaxonomyFields({ data, setData, errors, categories, tags }: ProductTaxonomyFieldsProps) {
    const tagsId = useId();

    const toggleTag = (id: number) => {
        setData('tag_ids', data.tag_ids.includes(id) ? data.tag_ids.filter((tagId) => tagId !== id) : [...data.tag_ids, id]);
    };

    return (
        <SectionCard title="Organización">
            <div className="flex flex-col">
                <SelectField
                    label="Categoría"
                    name="category_id"
                    options={[
                        { value: '', label: 'Sin categoría' },
                        ...categories.map((category) => ({ value: String(category.id), label: category.name })),
                    ]}
                    value={data.category_id}
                    onChange={(event) => setData('category_id', event.target.value)}
                    error={errors.category_id}
                    hint="Solo hace falta para publicar."
                />

                <TextField
                    label="…o crea una nueva"
                    name="category_name"
                    value={data.category_name}
                    onChange={(event) => setData('category_name', event.target.value)}
                    error={errors.category_name}
                    hint="Si ya tienes una con ese nombre, se usa la que tienes."
                    maxLength={120}
                    autoComplete="off"
                    disabled={data.category_id !== ''}
                />

                <div role="group" aria-labelledby={tagsId} className="fieldset">
                    <span id={tagsId} className="fieldset-legend">
                        Etiquetas
                    </span>

                    {tags.length === 0 ? (
                        <p className="text-ink-3 text-sm">Todavía no tienes etiquetas. Puedes crearlas aquí abajo.</p>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {tags.map((tag) => (
                                <Chip key={tag.id} active={data.tag_ids.includes(tag.id)} onClick={() => toggleTag(tag.id)}>
                                    {tag.name}
                                </Chip>
                            ))}
                        </div>
                    )}

                    {errors.tag_ids ? (
                        <p role="alert" className="label text-error text-xs">
                            {errors.tag_ids}
                        </p>
                    ) : null}
                </div>

                <div>
                    <TextField
                        label="Etiquetas nuevas"
                        name="tag_names"
                        value={data.tag_names.join(', ')}
                        onChange={(event) =>
                            setData(
                                'tag_names',
                                event.target.value
                                    .split(',')
                                    .map((name) => name.trim())
                                    .filter((name) => name !== ''),
                            )
                        }
                        hint="Separadas por comas. Las que ya tengas se reutilizan."
                        autoComplete="off"
                    />
                </div>
            </div>
        </SectionCard>
    );
}
