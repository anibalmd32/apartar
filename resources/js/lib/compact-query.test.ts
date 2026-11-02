import { compactQuery } from '@/lib/compact-query';
import { expect, it } from 'vitest';

it('deja en la URL solo los filtros activos', () => {
    expect(
        compactQuery(
            { al_limite: false, cerradas: true, estado: null, inactivas: false, pagina: 1, plan: '', q: 'moda', vencidas: false },
            { pagina: 1 },
        ),
    ).toEqual({ cerradas: 1, q: 'moda' });
});

it('conserva la página cuando no es la primera', () => {
    expect(compactQuery({ pagina: 3, operador: 7 }, { pagina: 1 })).toEqual({ pagina: 3, operador: 7 });
});
