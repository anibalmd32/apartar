import { type RowSelection, useRowSelection } from '@/features/data-table/hooks/useRowSelection';
import { type ProductListItem } from '@/types/product';

const idOf = (product: ProductListItem) => product.id;
const isDraft = (product: ProductListItem) => product.status === 'draft';
const nameOf = (product: ProductListItem) => product.name;

export function useDraftSelection(rows: readonly ProductListItem[]): RowSelection<ProductListItem> {
    return useRowSelection({ rows, getId: idOf, canSelect: isDraft, labelOf: nameOf, disabledHint: 'Solo los borradores se publican en bloque' });
}
