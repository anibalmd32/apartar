import { CURRENCY_COOKIE, CURRENCY_STORAGE_KEY, useCurrencyDisplay } from '@/features/currency/hooks/useCurrencyDisplay';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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

    it('lo recuerda en localStorage y en la cookie', () => {
        pageWith('usd_primary');

        const { result } = renderHook(() => useCurrencyDisplay());

        act(() => result.current.toggle());

        expect(localStorage.getItem(CURRENCY_STORAGE_KEY)).toBe('ves_primary');
        expect(document.cookie).toContain(`${CURRENCY_COOKIE}=ves_primary`);
    });

    it('no llama al servidor', () => {
        pageWith('usd_primary');

        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response());

        const { result } = renderHook(() => useCurrencyDisplay());

        act(() => result.current.toggle());

        expect(fetchSpy).not.toHaveBeenCalled();
    });
});

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

describe('al cambiar de organización', () => {
    it('conserva lo elegido aunque el negocio arranque en dólares', () => {
        pageWith('usd_primary');

        const first = renderHook(() => useCurrencyDisplay());
        act(() => first.result.current.toggle());
        first.unmount();

        pageWith('ves_primary');

        const second = renderHook(() => useCurrencyDisplay());

        expect(second.result.current.display).toBe('ves_primary');
        expect(document.cookie).toContain(`${CURRENCY_COOKIE}=ves_primary`);
    });
});

describe('todas las cifras de la página', () => {
    it('cambian a la vez cuando una instancia elige', () => {
        pageWith('usd_primary');

        const toggle = renderHook(() => useCurrencyDisplay());
        const label = renderHook(() => useCurrencyDisplay());

        act(() => toggle.result.current.toggle());

        expect(label.result.current.display).toBe('ves_primary');
    });

    it('una instancia nueva sigue lo elegido aunque la prop sea de la primera carga', () => {
        pageWith('usd_primary');
        const first = renderHook(() => useCurrencyDisplay());
        act(() => first.result.current.toggle());

        const late = renderHook(() => useCurrencyDisplay());

        expect(late.result.current.display).toBe('ves_primary');
    });
});
