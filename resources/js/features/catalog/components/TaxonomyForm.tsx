import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import { toSlug } from '@/lib/slug';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

/**
 * El formulario de una categoría o de una etiqueta, para el alta y para la
 * edición (`REQ-BOF-023`).
 *
 * **Uno solo para las cuatro combinaciones**, y no por ahorrar líneas: los dos
 * campos, sus reglas y la asimetría entre ellos son idénticos en las dos
 * entidades, y cuatro formularios serían cuatro sitios donde recordar que
 * renombrar no toca el enlace.
 *
 * ## El enlace se sugiere al crear y **no** se toca al renombrar
 *
 * Es RB-6, y es la decisión que este archivo tiene que hacer visible. Al crear
 * se propone el slug mientras se escribe el nombre, como en el registro del
 * negocio: quien ve `electrodomesticos` sugerido no escribe otra cosa. Al
 * editar, el campo del enlace arranca **cerrado**, y hay que abrirlo a
 * propósito — porque cambiarlo rompe los filtros que ya se compartieron por
 * WhatsApp, y una corrección de errata en el nombre no puede arrastrar ese
 * daño sin que nadie lo pida.
 *
 * Mientras está cerrado, el campo viaja con **el valor que ya tenía**: el
 * servidor ve un enlace idéntico al guardado y no lo cuenta como cambio, así
 * que renombrar una categoría con productos publicados funciona igual. La
 * regla no depende de que esta pantalla omita el campo —eso sería confiar la
 * invariante al formulario—, sino de que el servidor compare.
 */

interface TaxonomyFormProps {
    /** A dónde se envía el alta. */
    createUrl: string;
    /** Cómo se construye la URL de la edición a partir del identificador. */
    editUrl: (id: number) => string;
    /** Lo que se está editando; sin ello el formulario da de alta. */
    entry?: { readonly id: number; readonly name: string; readonly slug: string };
    /** Nombre en singular de lo que se crea, para los rótulos. */
    noun: string;
    /** Tope del nombre: 120 en categorías, 80 en etiquetas. */
    maxLength: number;
    onDone?: () => void;
}

interface TaxonomyFormData {
    [key: string]: string;
    name: string;
    slug: string;
}

export function TaxonomyForm({ createUrl, editUrl, entry, noun, maxLength, onDone }: TaxonomyFormProps) {
    const editing = entry !== undefined;

    const { data, setData, post, put, processing, errors, reset } = useForm<TaxonomyFormData>({
        name: entry?.name ?? '',
        slug: entry?.slug ?? '',
    });

    /** Al editar hay que pedir el cambio de enlace; al crear se sugiere solo. */
    const [slugOpen, setSlugOpen] = useState(!editing);

    const changeName = (value: string) => {
        setData((current) => ({
            ...current,
            name: value,
            // Solo se sugiere al crear. Sobrescribir el enlace de una
            // categoría que ya existe es exactamente el daño que RB-6 evita.
            slug: editing ? current.slug : toSlug(value),
        }));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (!editing) {
                    reset();
                }

                onDone?.();
            },
        };

        if (entry === undefined) {
            post(createUrl, options);
        } else {
            put(editUrl(entry.id), options);
        }
    };

    return (
        <form className="grid gap-2 sm:grid-cols-2" onSubmit={submit} noValidate>
            <TextField
                label={`Nombre de la ${noun}`}
                name="name"
                value={data.name}
                onChange={(event) => changeName(event.target.value)}
                error={errors.name}
                maxLength={maxLength}
                autoComplete="off"
                required
            />

            {slugOpen ? (
                <TextField
                    label="Enlace"
                    name="slug"
                    value={data.slug}
                    onChange={(event) => setData('slug', toSlug(event.target.value))}
                    error={errors.slug}
                    hint={
                        editing
                            ? 'Cambiarlo rompe los filtros que ya compartiste por enlace. Solo se puede mientras no tenga productos publicados.'
                            : 'Se usa en los enlaces de filtro de tus catálogos. Lo proponemos a partir del nombre.'
                    }
                    maxLength={maxLength}
                    autoComplete="off"
                />
            ) : (
                <div className="flex flex-col justify-end gap-1 pb-1">
                    <p className="text-sm opacity-70">
                        Enlace: <span className="font-mono">{data.slug}</span>
                    </p>

                    <div>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setSlugOpen(true)}>
                            Cambiar el enlace
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit" loading={processing}>
                    {editing ? 'Guardar cambios' : `Añadir ${noun}`}
                </Button>

                {onDone === undefined ? null : (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancelar
                    </Button>
                )}
            </div>
        </form>
    );
}
