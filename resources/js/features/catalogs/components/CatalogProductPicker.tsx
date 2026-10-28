import { Icon } from '@/components/ui/icon';
import { IconTextField } from '@/components/ui/icon-text-field';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type CatalogProductOption } from '@/types/catalog';
import { cilPlus, cilSearch } from '@coreui/icons';
import { type FocusEvent, useState } from 'react';

interface CatalogProductPickerProps {
    available: readonly CatalogProductOption[];
    chosenIds: readonly number[];
    suggestedIds: readonly number[];
    onAdd: (product: CatalogProductOption) => void;
}

const MAX_RESULTS = 8;

export function ProductThumb({ product }: { product: CatalogProductOption }) {
    return product.thumbUrl ? (
        <img src={product.thumbUrl} alt="" loading="lazy" className="bg-base-300 size-10 shrink-0 rounded-[10px] object-cover" />
    ) : (
        <ProductPlaceholder name={product.name} className="size-10 shrink-0 rounded-[10px] text-sm" />
    );
}

function OptionList({
    products,
    onAdd,
    caption,
}: {
    products: readonly CatalogProductOption[];
    onAdd: (product: CatalogProductOption) => void;
    caption?: string;
}) {
    return (
        <div className="bg-base-100 border-line shadow-pop w-full rounded-xl border p-1.5" onMouseDown={(event) => event.preventDefault()}>
            {caption ? <p className="text-ink-3 px-3 pt-1.5 pb-1 text-xs">{caption}</p> : null}
            <ul className="menu w-full p-0">
                {products.map((product) => (
                    <li key={product.id}>
                        <button type="button" onClick={() => onAdd(product)} className="flex items-center gap-3">
                            <ProductThumb product={product} />
                            <span className="flex min-w-0 flex-1 flex-col items-start">
                                <span className="truncate font-medium">{product.name}</span>
                                <span className="text-ink-3 text-xs">
                                    <MoneyLabel money={product.price} compact /> · {product.sku}
                                </span>
                            </span>
                            <span className="text-primary-ink inline-flex items-center gap-1 text-xs font-semibold">
                                <Icon icon={cilPlus} size={12} />
                                Añadir
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function CatalogProductPicker({ available, chosenIds, suggestedIds, onAdd }: CatalogProductPickerProps) {
    const [search, setSearch] = useState('');
    const [open, setOpen] = useState(false);

    const term = search.trim().toLowerCase();
    const free = available.filter((product) => !chosenIds.includes(product.id));

    const results =
        term === ''
            ? []
            : free.filter((product) => product.name.toLowerCase().includes(term) || product.sku.toLowerCase().includes(term)).slice(0, MAX_RESULTS);

    const suggestions = suggestedIds
        .map((id) => free.find((product) => product.id === id))
        .filter((product): product is CatalogProductOption => product !== undefined)
        .slice(0, MAX_RESULTS);

    const onBlur = (event: FocusEvent<HTMLDivElement>) => {
        if (!event.currentTarget.contains(event.relatedTarget)) {
            setOpen(false);
        }
    };

    return (
        <div
            className="flex flex-col gap-2"
            onFocus={() => setOpen(true)}
            onBlur={onBlur}
            onKeyDown={(event) => event.key === 'Escape' && setOpen(false)}
        >
            <IconTextField
                icon={cilSearch}
                label="Añadir productos"
                name="catalog-product-search"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                onClick={() => setOpen(true)}
                hint="Busca por nombre o por SKU."
                autoComplete="off"
            />

            {term !== '' && results.length === 0 ? (
                <p className="text-ink-3 text-sm">No queda ningún producto sin añadir que coincida con «{search.trim()}».</p>
            ) : null}

            {results.length > 0 ? <OptionList products={results} onAdd={onAdd} /> : null}

            {open && term === '' && suggestions.length > 0 ? (
                <OptionList products={suggestions} onAdd={onAdd} caption="Editados hace poco. Escribe para buscar entre todos tus productos." />
            ) : null}
        </div>
    );
}
