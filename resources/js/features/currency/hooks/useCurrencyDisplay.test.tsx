import { CURRENCY_COOKIE, CURRENCY_STORAGE_KEY, useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * La preferencia de moneda: de dónde sale, dónde se guarda y qué pasa cuando
 * no se puede guardar (`REQ-FX-004` RB-3, RB-4, RB-6 y SP-1).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage }));

function pageWith(display: unknown): void {
    usePage.mockReturnValue({
        props: {
            currency: {
                display,
                timezone: 'America/Caracas',
                rate: { rate: 42.5, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-19T09:00:00-04:00', isStale: false },
            },
        },
    });
}

beforeEach(() => {
    localStorage.clear();
    document.cookie = `${CURRENCY_COOKIE}=; path=/; max-age=0`;
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('el valor inicial llega resuelto del servidor', () => {
    /**
     * RB-4: **el primer render del servidor ya es el correcto.** Si el hook
     * descubriera la preferencia al montar, la página abriría en dólares y
     * saltaría a bolívares un instante después, en cada visita.
     */
    it('arranca con lo que mandan las page props', () => {
        pageWith('ves_primary');

        const { result } = renderHook(() => useCurrencyDisplay());

        expect(result.current.display).toBe('ves_primary');
        expect(result.current.isVesPrimary).toBe(true);
    });

    it('cae al default de plataforma si la prop viene rara', () => {
        pageWith('bolivares-por-favor');

        const { result } = renderHook(() => useCurrencyDisplay());

        expect(result.current.display).toBe('usd_primary');
    });
});

describe('cambiar de moneda', () => {
    it('alterna entre los dos modos', () => {
        pageWith('usd_primary');

        const { result } = renderHook(() => useCurrencyDisplay());

        act(() => result.current.toggle());
        expect(result.current.display).toBe('ves_primary');

        act(() => result.current.toggle());
        expect(result.current.display).toBe('usd_primary');
    });

    /**
     * RB-4: se guarda en **los dos** sitios. `localStorage` para la siguiente
     * pestaña y la cookie para el siguiente primer render del servidor.
     */
    it('lo recuerda en localStorage y en la cookie', () => {
        pageWith('usd_primary');

        const { result } = renderHook(() => useCurrencyDisplay());

        act(() => result.current.toggle());

        expect(localStorage.getItem(CURRENCY_STORAGE_KEY)).toBe('ves_primary');
        expect(document.cookie).toContain(`${CURRENCY_COOKIE}=ves_primary`);
    });

    /**
     * RB-6: cambiar es **puramente de cliente**. `MoneyView` ya trae las dos
     * representaciones, así que no hay nada que pedirle al servidor.
     */
    it('no llama al servidor', () => {
        pageWith('usd_primary');

        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response());

        const { result } = renderHook(() => useCurrencyDisplay());

        act(() => result.current.toggle());

        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

/**
 * **SP-1 · `localStorage` bloqueado no rompe nada.** En modo privado lanza al
 * leer y al escribir, y un catálogo que no abre porque el navegador no deja
 * guardar una preferencia es un catálogo que no vende.
 */
describe('con el almacenamiento bloqueado', () => {
    it('sigue cambiando de moneda y escribe la cookie igual', () => {
        pageWith('usd_primary');

        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('QuotaExceededError');
        });

        const { result } = renderHook(() => useCurrencyDisplay());

        expect(() => act(() => result.current.toggle())).not.toThrow();

        expect(result.current.display).toBe('ves_primary');
        expect(document.cookie).toContain(`${CURRENCY_COOKIE}=ves_primary`);
    });

    /**
     * Y con **los dos** almacenes caídos la preferencia vive en memoria
     * durante la sesión: el toggle sigue funcionando y la página sigue
     * abriendo.
     */
    it('funciona en memoria cuando tampoco hay cookie', () => {
        pageWith('usd_primary');

        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('QuotaExceededError');
        });

        const cookie = vi.spyOn(document, 'cookie', 'set').mockImplementation(() => {
            throw new DOMException('SecurityError');
        });

        const { result } = renderHook(() => useCurrencyDisplay());

        expect(() => act(() => result.current.toggle())).not.toThrow();

        expect(result.current.display).toBe('ves_primary');
        expect(cookie).toHaveBeenCalled();
    });
});

/**
 * RB-3 · **la preferencia es de la persona, no del negocio.** El hook no sabe
 * de organizaciones y la cookie no se parte por ninguna: quien navega el
 * catálogo de dos negocios en bolívares no vuelve a pulsar el toggle en el
 * segundo.
 */
describe('al cambiar de organización', () => {
    it('conserva lo elegido aunque el negocio arranque en dólares', () => {
        pageWith('usd_primary');

        const first = renderHook(() => useCurrencyDisplay());
        act(() => first.result.current.toggle());
        first.unmount();

        // El servidor del segundo negocio ya lee la cookie y manda
        // `ves_primary`, aunque su `currency_display_default` sea dólares.
        pageWith('ves_primary');

        const second = renderHook(() => useCurrencyDisplay());

        expect(second.result.current.display).toBe('ves_primary');
        expect(document.cookie).toContain(`${CURRENCY_COOKIE}=ves_primary`);
    });
});
