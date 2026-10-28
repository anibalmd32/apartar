import { withSuggestedTags } from '@/features/products/lib/tags';
import { expect, it } from 'vitest';

const known = [
    { id: 1, name: 'Mochilas' },
    { id: 2, name: 'Viaje' },
];

it('marca las etiquetas que ya existen y crea las demás, sin repetir', () => {
    expect(withSuggestedTags(['mochilas', 'impermeable', 'viáje', 'Impermeable'], known, { tagIds: [2], tagNames: [] })).toEqual({
        tagIds: [2, 1],
        tagNames: ['impermeable'],
    });
});

it('respeta las etiquetas nuevas que ya escribió la persona', () => {
    expect(withSuggestedTags(['urbano'], known, { tagIds: [], tagNames: ['Urbano'] })).toEqual({ tagIds: [], tagNames: ['Urbano'] });
});
