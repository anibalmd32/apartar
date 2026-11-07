import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { bo } from '@/lib/surfaces';
import { type TagView } from '@/types/taxonomy';
import { cilPencil, cilTag, cilTrash } from '@coreui/icons';
import { useState } from 'react';

interface TagRowProps {
    tag: TagView;
    onEdit: () => void;
}

export function TagRow({ tag, onEdit }: TagRowProps) {
    const [confirming, setConfirming] = useState(false);

    return (
        <tr>
            <td data-cell="primary">
                <span className="bg-primary-soft text-primary-ink inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold">
                    <Icon icon={cilTag} size={12} />
                    {tag.name}
                </span>
            </td>

            <td data-cell="shrink" className="text-ink-2">
                /{tag.slug}
            </td>

            <td data-cell="shrink" className={tag.productsCount > 0 ? '' : 'text-warning'}>
                {tag.productsCount === 1 ? '1 producto' : `${tag.productsCount} productos`}
            </td>

            <td data-cell="actions" className="text-right">
                <div className="flex flex-wrap justify-end gap-1">
                    <Button size="sm" variant="ghost" className="text-ink-2 btn-square" onClick={onEdit} aria-label={`Editar ${tag.name}`}>
                        <Icon icon={cilPencil} />
                        <span className="sr-only">Editar</span>
                    </Button>

                    <Button
                        size="sm"
                        variant="ghost"
                        className="text-ink-2 hover:text-error btn-square"
                        onClick={() => setConfirming(true)}
                        aria-label={`Eliminar ${tag.name}`}
                    >
                        <Icon icon={cilTrash} />
                        <span className="sr-only">Eliminar</span>
                    </Button>
                </div>
                <ConfirmDialog
                    open={confirming}
                    onClose={() => setConfirming(false)}
                    title={`¿Eliminar la etiqueta «${tag.name}»?`}
                    description={
                        tag.productsCount > 0
                            ? `Se quita de ${tag.productsCount === 1 ? '1 producto' : `${tag.productsCount} productos`}. Los productos no se borran.`
                            : 'No la usa ningún producto.'
                    }
                    confirmLabel="Eliminar etiqueta"
                    url={bo(`/etiquetas/${tag.id}`)}
                />
            </td>
        </tr>
    );
}
