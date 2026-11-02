import { useLeaveGuard } from '@/hooks/use-leave-guard';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

type BeforeListener = (event: { detail: { visit: Record<string, unknown> }; preventDefault: () => void }) => void;

const { listeners, visit } = vi.hoisted(() => ({ listeners: [] as BeforeListener[], visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: {
        visit,
        on: (_name: string, listener: BeforeListener) => {
            listeners.push(listener);

            return () => listeners.splice(listeners.indexOf(listener), 1);
        },
    },
}));

function Editor({ dirty }: { dirty: boolean }) {
    return <>{useLeaveGuard(dirty, { title: '¿Salir sin guardar?', description: 'Se pierden los cambios.' })}</>;
}

function navigate(overrides: Record<string, unknown> = {}) {
    const preventDefault = vi.fn();
    const pending = { method: 'get', only: [], prefetch: false, url: new URL('http://localhost/backoffice/productos'), ...overrides };

    act(() => listeners.forEach((listener) => listener({ detail: { visit: pending }, preventDefault })));

    return preventDefault;
}

function closeTab() {
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);

    return unload.defaultPrevented;
}

beforeEach(() => {
    listeners.length = 0;
    visit.mockReset();
});

it('con cambios sin guardar, pide confirmar antes de irse a otra página', () => {
    render(<Editor dirty />);

    expect(navigate()).toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: '¿Salir sin guardar?' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Salir sin guardar' }));

    expect(visit).toHaveBeenCalledWith('http://localhost/backoffice/productos', expect.any(Object));
});

it('seguir editando cierra el aviso y no navega', () => {
    render(<Editor dirty />);
    navigate();

    fireEvent.click(screen.getByRole('button', { name: 'Seguir editando' }));

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(visit).not.toHaveBeenCalled();
});

it('no se interpone al guardar ni al recalcular el plan', () => {
    render(<Editor dirty />);

    expect(navigate({ method: 'put' })).not.toHaveBeenCalled();
    expect(navigate({ only: ['plan'] })).not.toHaveBeenCalled();
    expect(navigate({ prefetch: true })).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog')).toBeNull();
});

it('sin cambios deja salir y el navegador no pregunta al cerrar la pestaña', () => {
    render(<Editor dirty={false} />);

    expect(navigate()).not.toHaveBeenCalled();
    expect(closeTab()).toBe(false);
});

it('con cambios, el navegador pregunta antes de cerrar o recargar la pestaña', () => {
    render(<Editor dirty />);

    expect(closeTab()).toBe(true);
});
