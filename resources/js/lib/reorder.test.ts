import { moved } from '@/lib/reorder';
import { expect, it } from 'vitest';

it('mueve un elemento a otra posición sin tocar la lista original', () => {
    const list = ['a', 'b', 'c'];

    expect(moved(list, 2, 0)).toEqual(['c', 'a', 'b']);
    expect(moved(list, 0, 1)).toEqual(['b', 'a', 'c']);
    expect(list).toEqual(['a', 'b', 'c']);
});

it('una posición fuera de la lista la deja igual', () => {
    expect(moved(['a', 'b'], 1, 2)).toEqual(['a', 'b']);
    expect(moved(['a', 'b'], -1, 0)).toEqual(['a', 'b']);
});
