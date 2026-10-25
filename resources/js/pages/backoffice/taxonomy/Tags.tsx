import { SectionCard } from '@/components/ui/section-card';
import { TagRow } from '@/features/catalog/components/TagRow';
import { TaxonomyForm } from '@/features/catalog/components/TaxonomyForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type TagView } from '@/types/taxonomy';
import { useState } from 'react';

interface TagsPageProps {
    tags: TagView[];
    [key: string]: unknown;
}

export default function TagsIndex({ tags }: TagsPageProps) {
    const [editing, setEditing] = useState<number | null>(null);

    const editable = tags.find((tag) => tag.id === editing);

    return (
        <BackofficeLayout title="Etiquetas" description="Marcas sueltas para afinar lo que tus clientes encuentran dentro de un catálogo.">
            <SectionCard
                title={editable ? `Editar «${editable.name}»` : 'Nueva etiqueta'}
                subtitle="Un producto tiene una sola categoría y las etiquetas que quieras. Al filtrar, dos etiquetas marcadas muestran lo que lleva cualquiera de las dos."
            >
                <TaxonomyForm
                    key={editable?.id ?? 'nueva'}
                    createUrl="/backoffice/etiquetas"
                    editUrl={(id) => `/backoffice/etiquetas/${id}`}
                    entry={editable}
                    noun="etiqueta"
                    maxLength={80}
                    onDone={editable ? () => setEditing(null) : undefined}
                />
            </SectionCard>

            <div className="flex flex-col gap-2.5">
                <SectionCard flush aria-label="Tus etiquetas" className="overflow-hidden">
                    {tags.length === 0 ? (
                        <p className="text-ink-3 p-5 text-sm">Todavía no tienes ninguna. Añade la primera arriba.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Etiqueta</th>
                                        <th scope="col">Enlace</th>
                                        <th scope="col">Uso</th>
                                        <th scope="col" className="text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {tags.map((tag) => (
                                        <TagRow key={tag.id} tag={tag} onEdit={() => setEditing(tag.id)} />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </SectionCard>

                <p className="text-ink-3 text-xs">
                    Eliminar una etiqueta la quita de sus productos sin borrarlos. Si alguien tenía ese filtro en un enlace compartido, el enlace
                    sigue funcionando y el filtro se ignora.
                </p>
            </div>
        </BackofficeLayout>
    );
}
