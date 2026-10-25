import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { type TagView } from '@/types/taxonomy';
import { cilPencil, cilTag, cilTrash } from '@coreui/icons';
import { router } from '@inertiajs/react';

interface TagRowProps {
    tag: TagView;
    onEdit: () => void;
}

export function TagRow({ tag, onEdit }: TagRowProps) {
    const remove = () => router.delete(`/backoffice/etiquetas/${tag.id}`, { preserveScroll: true });

    return (
        <tr>
            <td>
                <span className="bg-primary-soft text-primary-ink inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold">
                    <Icon icon={cilTag} size={12} />
                    {tag.name}
                </span>
            </td>

            <td className="text-ink-2">/{tag.slug}</td>

            <td className={tag.productsCount > 0 ? '' : 'text-warning'}>
                {tag.productsCount === 1 ? '1 producto' : `${tag.productsCount} productos`}
            </td>

            <td className="text-right">
                <div className="flex flex-wrap justify-end gap-1">
                    <Button size="sm" variant="ghost" className="btn-square text-ink-2" onClick={onEdit} aria-label={`Editar ${tag.name}`}>
                        <Icon icon={cilPencil} />
                    </Button>

                    <Button
                        size="sm"
                        variant="ghost"
                        className="btn-square text-ink-2 hover:text-error"
                        onClick={remove}
                        aria-label={`Eliminar ${tag.name}`}
                    >
                        <Icon icon={cilTrash} />
                    </Button>
                </div>
            </td>
        </tr>
    );
}
