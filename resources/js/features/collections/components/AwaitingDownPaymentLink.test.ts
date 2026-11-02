import { awaitingHref } from '@/features/collections/components/CollectionSummary';
import { expect, it } from 'vitest';

it('el enlace a los que esperan la inicial conserva la categoría, el catálogo y la búsqueda', () => {
    const href = awaitingHref({ category: '7', catalog: null, q: 'ana', from: '2026-09-01' });

    expect(href).toContain('preset=awaiting_down_payment');
    expect(href).toContain('category=7');
    expect(href).toContain('q=ana');
    expect(href).not.toContain('catalog=');
    expect(href).not.toContain('from=');
});
