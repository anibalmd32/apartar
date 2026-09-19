import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type TagView } from '@/types/taxonomy';
import { router } from '@inertiajs/react';

/**
 * Una etiqueta en la lista (`REQ-BOF-023` RB-5).
 *
 * Más corta que la fila de una categoría, y la diferencia es el requisito: no
 * hay reasignación ni botones de orden, y **eliminar funciona siempre**. Una
 * etiqueta es una marca suelta: se desasocia de sus productos y ninguno pierde
 * nada, al contrario que una categoría, que un producto necesita tener.
 *
 * El aviso del borrado dice qué va a pasar con los enlaces compartidos, porque
 * es lo único que esta operación se lleva por delante: un filtro de etiqueta
 * repartido por WhatsApp deja de encontrar la etiqueta y **se ignora** al
 * abrirlo (SP-4). No es un error ni rompe el catálogo; simplemente el enlace
 * enseña de más.
 */

interface TagRowProps {
    tag: TagView;
    onEdit: () => void;
}

export function TagRow({ tag, onEdit }: TagRowProps) {
    const remove = () => router.delete(`/backoffice/etiquetas/${tag.id}`, { preserveScroll: true });

    return (
        <tr>
            <td>
                <div className="flex flex-col">
                    <span className="font-medium">{tag.name}</span>
                    <span className="font-mono text-xs opacity-70">{tag.slug}</span>
                </div>
            </td>

            <td>
                <Badge tone={tag.productsCount > 0 ? 'info' : 'neutral'}>
                    {tag.productsCount === 1 ? '1 producto' : `${tag.productsCount} productos`}
                </Badge>
            </td>

            <td className="text-right">
                <div className="flex flex-wrap justify-end gap-1">
                    <Button size="sm" variant="ghost" onClick={onEdit}>
                        Editar
                    </Button>

                    <Button size="sm" variant="ghost" onClick={remove}>
                        Eliminar
                    </Button>
                </div>
            </td>
        </tr>
    );
}
