import { TagRow } from '@/features/catalog/components/TagRow';
import { TaxonomyForm } from '@/features/catalog/components/TaxonomyForm';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type TagView } from '@/types/taxonomy';
import { useState } from 'react';

/**
 * Las etiquetas del inventario (`REQ-BOF-023`).
 *
 * Hermana de la pantalla de categorías y deliberadamente más corta: sin orden
 * manual —las etiquetas se listan por nombre— y sin reasignación, porque
 * borrar una no deja ningún producto sin sitio (RB-5).
 *
 * Un producto lleva **una** categoría obligatoria y **N** etiquetas
 * opcionales (`REQ-CAT-006` RB-2), y el filtro del catálogo las combina con
 * semántica **O**: marcar dos etiquetas enseña lo que lleva cualquiera de las
 * dos, no lo que lleva las dos. Está dicho en la pantalla porque es lo que
 * decide cómo el negocio las reparte entre sus productos.
 */

interface TagsPageProps {
    tags: TagView[];
    [key: string]: unknown;
}

export default function TagsIndex({ tags }: TagsPageProps) {
    const [editing, setEditing] = useState<number | null>(null);

    const editable = tags.find((tag) => tag.id === editing);

    return (
        <BackofficeLayout title="Etiquetas" description="Marcas sueltas para afinar lo que tus clientes encuentran dentro de un catálogo.">
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <h2 className="card-title text-base">{editable ? 'Editar etiqueta' : 'Añadir una etiqueta'}</h2>

                    <TaxonomyForm
                        key={editable?.id ?? 'nueva'}
                        createUrl="/backoffice/etiquetas"
                        editUrl={(id) => `/backoffice/etiquetas/${id}`}
                        entry={editable}
                        noun="etiqueta"
                        maxLength={80}
                        onDone={editable ? () => setEditing(null) : undefined}
                    />

                    <p className="text-xs opacity-70">
                        Un producto tiene una sola categoría y las etiquetas que quieras. Al filtrar, dos etiquetas marcadas muestran lo que lleva
                        cualquiera de las dos.
                    </p>
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <h2 className="card-title text-base">Tus etiquetas</h2>

                    {tags.length === 0 ? (
                        <p className="text-sm opacity-70">Todavía no tienes ninguna. Añade la primera arriba.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Etiqueta</th>
                                        <th scope="col">Productos</th>
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

                    <p className="text-xs opacity-70">
                        Eliminar una etiqueta la quita de sus productos sin borrarlos. Si alguien tenía ese filtro en un enlace compartido, el enlace
                        sigue funcionando y el filtro se ignora.
                    </p>
                </div>
            </section>
        </BackofficeLayout>
    );
}
