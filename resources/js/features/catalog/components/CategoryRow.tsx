import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { SortableHandle, SortableRow } from '@/components/ui/sortable';
import { DeleteCategoryDialog } from '@/features/catalog/components/DeleteCategoryDialog';
import { type CategoryView } from '@/types/taxonomy';
import { cilPencil, cilTrash } from '@coreui/icons';
import { useState } from 'react';

interface CategoryRowProps {
    category: CategoryView;
    others: readonly CategoryView[];
    onEdit: () => void;
}

export function CategoryRow({ category, others, onEdit }: CategoryRowProps) {
    const url = `/backoffice/categorias/${category.id}`;

    const [asking, setAsking] = useState(false);

    const canReassign = category.productsCount > 0 && others.length > 0;
    const remove = () => setAsking(true);

    return (
        <SortableRow id={category.id} label={category.name}>
            {({ containerProps, handleProps }) => (
                <tr ref={containerProps.ref} style={containerProps.style} className={containerProps.className}>
                    <td className="w-10 pr-0">
                        <SortableHandle handleProps={handleProps} />
                    </td>

                    <td>
                        <div className="flex flex-col">
                            <span className="font-semibold">{category.name}</span>
                            <span className="text-ink-3 text-xs">/{category.slug}</span>
                        </div>
                    </td>

                    <td>
                        <Badge count tone={category.productsCount > 0 ? 'neutral' : 'warning'}>
                            {category.productsCount === 1 ? '1 producto' : `${category.productsCount} productos`}
                        </Badge>
                    </td>

                    <td className="text-right">
                        <div className="flex flex-wrap items-end justify-end gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                className="btn-square text-ink-2"
                                onClick={onEdit}
                                aria-label={`Editar ${category.name}`}
                            >
                                <Icon icon={cilPencil} />
                            </Button>

                            <Button
                                size="sm"
                                variant="ghost"
                                className="btn-square text-ink-2 hover:text-error"
                                onClick={remove}
                                aria-label={`Eliminar ${category.name}`}
                            >
                                <Icon icon={cilTrash} />
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
