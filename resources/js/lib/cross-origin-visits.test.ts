import { guardCrossOriginVisits, leavesOrigin } from '@/lib/cross-origin-visits';
import { beforeEach, describe, expect, it, vi } from 'vitest';

type BeforeListener = (event: { detail: { visit: { method: string; url: URL } } }) => boolean | void;

let listener: BeforeListener | null = null;

vi.mock('@inertiajs/react', () => ({
    router: {
        on: (_name: string, callback: BeforeListener) => {
            listener = callback;

            return () => {
                listener = null;
            };
        },
    },
}));

const visit = (method: string, href: string) => listener?.({ detail: { visit: { method, url: new URL(href, window.location.href) } } });

describe('las visitas que cambian de subdominio', () => {
    beforeEach(() => {
        listener = null;
    });

    it('se hacen con una carga completa en vez de una petición de Inertia', () => {
        const navigate = vi.fn();
        guardCrossOriginVisits(navigate);

        expect(visit('get', 'https://negocio.apartar.app/login')).toBe(false);
        expect(navigate).toHaveBeenCalledWith('https://negocio.apartar.app/login');
    });

    it('dentro del mismo dominio siguen siendo visitas de Inertia', () => {
        const navigate = vi.fn();
        guardCrossOriginVisits(navigate);

        expect(visit('get', '/precios')).toBeUndefined();
        expect(visit('post', 'https://negocio.apartar.app/login')).toBeUndefined();
        expect(navigate).not.toHaveBeenCalled();
    });

    it('compara el origen completo, no solo la ruta', () => {
        expect(leavesOrigin(new URL('https://apartar.app/registro'), 'https://apartar.app')).toBe(false);
        expect(leavesOrigin(new URL('https://plataforma.apartar.app/'), 'https://apartar.app')).toBe(true);
    });
});
