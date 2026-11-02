import { guardHistoryPops, onHistoryPop } from '@/lib/history-guard';
import { afterEach, expect, it, vi } from 'vitest';

const cleanups: (() => void)[] = [];

afterEach(() => cleanups.splice(0).forEach((cleanup) => cleanup()));

function popWith(guard: () => boolean) {
    cleanups.push(guardHistoryPops());
    cleanups.push(onHistoryPop(guard));
    const inertia = vi.fn();
    window.addEventListener('popstate', inertia);
    cleanups.push(() => window.removeEventListener('popstate', inertia));

    window.dispatchEvent(new PopStateEvent('popstate', { state: null }));

    return inertia;
}

it('si un formulario frena el atrás, Inertia no llega a cambiar de página aunque escuche después', () => {
    expect(popWith(() => true)).not.toHaveBeenCalled();
});

it('si nadie lo frena, el atrás sigue su curso normal', () => {
    expect(popWith(() => false)).toHaveBeenCalledOnce();
});
