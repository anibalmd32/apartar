import { CatalogFilterPanel } from '@/features/catalog/components/CatalogFilterPanel';
import { SearchInput } from '@/features/catalog/components/SearchInput';
import { type CatalogFacets, type CatalogFilters } from '@/types/catalog-view';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const FACETS: CatalogFacets = {
    categories: [
        { slug: 'taladros', name: 'Taladros', count: 4 },
        { slug: 'sierras', name: 'Sierras', count: 2 },
    ],
    tags: [{ slug: 'oferta', name: 'Oferta', count: 3 }],
};

const APPLIED: CatalogFilters = { q: null, category: null, tags: [], sort: 'price_asc', ignored: false };

function panel() {
    return render(
        <CatalogFilterPanel
            facets={FACETS}
            applied={APPLIED}
            searching={false}
            search={<SearchInput value="" onChange={vi.fn()} />}
            onCategory={vi.fn()}
            onToggleTag={vi.fn()}
            onSort={vi.fn()}
        />,
    );
}

it('en el celular el orden, las categorías y las etiquetas van en una sola fila que se desliza', () => {
    panel();

    const row = screen.getByRole('group', { name: 'Categoría' }).parentElement;

    expect(row).toHaveClass('scroll-row');
    expect(row).toContainElement(screen.getByRole('group', { name: 'Etiquetas' }));
    expect(row).toContainElement(screen.getByLabelText('Ordenar por'));
});

it('el control de orden muestra el orden elegido', () => {
    const { container } = panel();

    expect(container.querySelector('span[aria-hidden="true"].select')).toHaveTextContent('Precio: de menor a mayor');
});

it('el teclado del celular muestra «Buscar» en la búsqueda', () => {
    panel();

    expect(screen.getByRole('searchbox', { name: 'Buscar en este catálogo' })).toHaveAttribute('enterkeyhint', 'search');
});
