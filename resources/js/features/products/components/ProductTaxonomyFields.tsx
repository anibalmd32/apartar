import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type ProductFieldsProps } from '@/features/products/hooks/useProductForm';
import { type TaxonomyOption } from '@/types/product';

/**
 * Cómo se encuentra el producto: su categoría y sus etiquetas
 * (`REQ-CAT-006` RB-2, `REQ-BOF-023` RB-8).
 *
 * ## Elegir de la lista o escribir una nueva, y son dos campos
 *
 * No es redundancia: un identificador se busca dentro del negocio —y uno ajeno
 * es un intento de cruzar la frontera que la acción rechaza— mientras que un
 * nombre que ya existe **se reutiliza** en vez de fallar por duplicado
 * (`REQ-BOF-023` RB-8, SP-6). Colapsarlos en un solo campo obligaría a
 * adivinar cuál de las dos cosas quiso decir quien lo envió.
 *
 * El campo de crear se deshabilita cuando ya hay una elegida, porque mandar
 * las dos cosas a la vez sería pedir dos categorías para un producto que solo
 * puede tener una.
 *
 * Un producto lleva **una** categoría —obligatoria solo para publicar— y
 * **N** etiquetas opcionales.
 */

interface ProductTaxonomyFieldsProps extends ProductFieldsProps {
    categories: readonly TaxonomyOption[];
    tags: readonly TaxonomyOption[];
}

export function ProductTaxonomyFields({ data, setData, errors, categories, tags }: ProductTaxonomyFieldsProps) {
    const toggleTag = (id: number) => {
        setData('tag_ids', data.tag_ids.includes(id) ? data.tag_ids.filter((tagId) => tagId !== id) : [...data.tag_ids, id]);
    };

    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body grid gap-2 sm:grid-cols-2">
                <h2 className="card-title text-base sm:col-span-2">Cómo se encuentra</h2>

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

                <div className="sm:col-span-2">
                    <span className="fieldset-legend">Etiquetas</span>

                    {tags.length === 0 ? (
                        <p className="text-sm opacity-70">Todavía no tienes etiquetas. Puedes crearlas aquí abajo.</p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {tags.map((tag) => (
                                <label key={tag.id} className="label cursor-pointer gap-2">
                                    <input
                                        type="checkbox"
                                        className="checkbox checkbox-sm"
                                        checked={data.tag_ids.includes(tag.id)}
                                        onChange={() => toggleTag(tag.id)}
                                    />
                                    <span className="label-text">{tag.name}</span>
                                </label>
                            ))}
                        </div>
                    )}

                    {errors.tag_ids ? (
                        <p role="alert" className="label text-error text-xs">
                            {errors.tag_ids}
                        </p>
                    ) : null}
                </div>

                <div className="sm:col-span-2">
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
        </section>
    );
}
