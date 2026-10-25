import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { TextField } from '@/components/ui/text-field';
import { toSlug } from '@/lib/slug';
import { cilPlus } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler, useState } from 'react';

interface TaxonomyFormProps {
    createUrl: string;
    editUrl: (id: number) => string;
    entry?: { readonly id: number; readonly name: string; readonly slug: string };
    noun: string;
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

    const [slugOpen, setSlugOpen] = useState(!editing);

    const changeName = (value: string) => {
        setData((current) => ({
            ...current,
            name: value,
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
        <form className="grid grid-cols-1 items-start gap-x-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]" onSubmit={submit} noValidate>
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
                <div className="flex flex-col gap-1 sm:pt-[30px]">
                    <p className="text-ink-2 text-sm">
                        Enlace: <span className="text-ink-3">/{data.slug}</span>
                    </p>

                    <div>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setSlugOpen(true)}>
                            Cambiar el enlace
                        </Button>
                    </div>
                </div>
            )}

            <div className="flex gap-2 pt-2 sm:pt-[30px]">
                <Button type="submit" loading={processing}>
                    {editing ? null : <Icon icon={cilPlus} />}
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
