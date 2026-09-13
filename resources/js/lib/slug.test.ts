import { suggestSlug, toSlug } from '@/lib/slug';
import { describe, expect, it } from 'vitest';

/**
 * `REQ-ORG-001` RB-2 y SP-7.
 */
describe('sugerencia del enlace del negocio', () => {
    it('convierte el nombre en un segmento de URL', () => {
        expect(toSlug('Ferretería El Tornillo')).toBe('ferreteria-el-tornillo');
        expect(toSlug('Bodegón 24/7')).toBe('bodegon-24-7');
    });

    it('quita los acentos en vez de descartar la palabra', () => {
        // `Peluquería` sin esto quedaría en `peluquer-a`, que es peor que
        // inservible: parece un error de la persona que escribió el nombre.
        expect(toSlug('Peluquería Ñandú')).toBe('peluqueria-nandu');
    });

    it('no deja guiones sueltos en los extremos', () => {
        expect(toSlug('  ¡Tienda!  ')).toBe('tienda');
        expect(toSlug('--tienda--')).toBe('tienda');
    });

    /**
     * SP-7 · un nombre que no produce enlace devuelve vacío, y el vacío es la
     * señal de que hace falta escribirlo a mano. Inventarle uno le daría al
     * negocio una URL que no sabría explicar a sus clientes.
     */
    it('devuelve vacío con un nombre que no deja nada convertible', () => {
        expect(toSlug('🎉🎉🎉')).toBe('');
        expect(toSlug('¡¿!?')).toBe('');
    });

    it('respeta los cien caracteres de la columna', () => {
        const slug = toSlug('a'.repeat(150));

        expect(slug.length).toBe(100);
    });

    it('sugiere mientras nadie haya tocado el campo', () => {
        expect(suggestSlug('Ferretería El Tornillo', '', false)).toBe('ferreteria-el-tornillo');
    });

    /**
     * Y deja de sugerir en cuanto la persona escribe: sobrescribir lo que
     * alguien acaba de teclear es la clase de ayuda que se recuerda con rabia.
     */
    it('deja de sugerir en cuanto el enlace se edita a mano', () => {
        expect(suggestSlug('Ferretería El Tornillo', 'el-tornillo', true)).toBe('el-tornillo');
    });
});
