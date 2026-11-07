import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { SortableHandle, SortableRow } from '@/components/ui/sortable';
import { DeleteCategoryDialog } from '@/features/catalog/components/DeleteCategoryDialog';
import { bo } from '@/lib/surfaces';
import { type CategoryView } from '@/types/taxonomy';
import { cilArrowBottom, cilArrowTop, cilPencil, cilTrash } from '@coreui/icons';
import { useState } from 'react';

interface CategoryRowProps {
    category: CategoryView;
    others: readonly CategoryView[];
    onEdit: () => void;
    onMoveUp?: () => void;
    onMoveDown?: () => void;
}

export function CategoryRow({ category, others, onEdit, onMoveUp, onMoveDown }: CategoryRowProps) {
    const url = bo(`/categorias/${category.id}`);

    const [asking, setAsking] = useState(false);

    const canReassign = category.productsCount > 0 && others.length > 0;
    const remove = () => setAsking(true);

    return (
        <SortableRow id={category.id} label={category.name}>
            {({ containerProps, handleProps }) => (
                <tr ref={containerProps.ref} style={containerProps.style} className={containerProps.className}>
                    <td data-cell="shrink" className="w-10 pr-0 max-md:hidden!">
                        <SortableHandle handleProps={handleProps} />
                    </td>

                    <td data-cell="primary">
                        <div className="flex min-w-0 flex-col">
                            <span className="font-semibold">{category.name}</span>
                            <span className="text-ink-3 truncate text-xs">/{category.slug}</span>
                        </div>
                    </td>

                    <td data-cell="shrink">
                        <Badge count tone={category.productsCount > 0 ? 'neutral' : 'warning'}>
                            {category.productsCount === 1 ? '1 producto' : `${category.productsCount} productos`}
                        </Badge>
                    </td>

                    <td data-cell="actions" className="text-right">
                        <div className="flex flex-wrap items-end justify-end gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-ink-2 disabled:opacity-40 md:hidden"
                                onClick={onMoveUp}
                                disabled={onMoveUp === undefined}
                                aria-label={`Subir ${category.name}`}
                            >
                                <Icon icon={cilArrowTop} />
                            </Button>

                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-ink-2 disabled:opacity-40 md:hidden"
                                onClick={onMoveDown}
                                disabled={onMoveDown === undefined}
                                aria-label={`Bajar ${category.name}`}
                            >
                                <Icon icon={cilArrowBottom} />
                            </Button>

                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-ink-2 btn-square"
                                onClick={onEdit}
                                aria-label={`Editar ${category.name}`}
                            >
                                <Icon icon={cilPencil} />
                                <span className="sr-only">Editar</span>
                            </Button>

                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-ink-2 hover:text-error btn-square"
                                onClick={remove}
                                aria-label={`Eliminar ${category.name}`}
                            >
                                <Icon icon={cilTrash} />
                                <span className="sr-only">Eliminar</span>
                            </Button>
                        </div>
                        {canReassign ? (
                            <DeleteCategoryDialog category={category} others={others} open={asking} onClose={() => setAsking(false)} />
                        ) : (
                            <ConfirmDialog
                                open={asking}
                                onClose={() => setAsking(false)}
                                title={`¿Eliminar la categoría «${category.name}»?`}
                                description={
                                    category.productsCount > 0 ? 'Sus productos se quedan sin categoría. No se borran.' : 'No tiene productos.'
                                }
                                confirmLabel="Eliminar categoría"
                                url={url}
                            />
                        )}
                    </td>
                </tr>
            )}
        </SortableRow>
    );
}
