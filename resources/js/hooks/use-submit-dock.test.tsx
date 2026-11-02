import { FormActionBar } from '@/components/ui/form-action-bar';
import { PageActions } from '@/components/ui/page-actions';
import { useSubmitDock } from '@/hooks/use-submit-dock';
import { act, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

let report: ((entries: { isIntersecting: boolean }[]) => void) | null = null;

class FakeObserver {
    constructor(callback: (entries: { isIntersecting: boolean }[]) => void) {
        report = callback;
    }

    observe() {}

    disconnect() {}
}

afterEach(() => {
    report = null;
    vi.unstubAllGlobals();
});

function Page({ needed }: { needed: boolean }) {
    const dock = useSubmitDock(needed);

    return (
        <>
            <PageActions actions={[{ label: 'Crear producto', form: 'f', variant: 'primary', primary: true, elementRef: dock.headerRef }]} />
            <form id="f">
                <FormActionBar sticky={dock.sticky}>
                    <button type="submit">Crear producto abajo</button>
                </FormActionBar>
            </form>
        </>
    );
}

function bar() {
    return screen.getByRole('button', { name: 'Crear producto abajo' }).parentElement;
}

it('la barra de abajo solo queda fija cuando el botón de la cabecera ya no se ve', () => {
    vi.stubGlobal('IntersectionObserver', FakeObserver);

    render(<Page needed />);

    act(() => report?.([{ isIntersecting: true }]));
    expect(bar()).not.toHaveAttribute('data-sticky');

    act(() => report?.([{ isIntersecting: false }]));
    expect(bar()).toHaveAttribute('data-sticky');
});

it('sin cambios por guardar la barra no queda fija aunque la cabecera no se vea', () => {
    vi.stubGlobal('IntersectionObserver', FakeObserver);

    render(<Page needed={false} />);

    act(() => report?.([{ isIntersecting: false }]));
    expect(bar()).not.toHaveAttribute('data-sticky');
});
