import { MergeCustomerDialog } from '@/features/customers/components/MergeCustomerDialog';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | T, value?: unknown) =>
                setState((previous) => (typeof key === 'object' ? key : { ...previous, [key]: value }));

            return { data, setData, errors: {}, processing: false, post: vi.fn() };
        },
    };
});

const candidate = {
    id: 5,
    name: 'Gabriela Suárez',
    document: 'V-24.567.890',
    phone: null,
    blocked: false,
    moves: { layaways: 1, receipts: 2, login_tokens: 0, account_moves: false },
};

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify({ candidates: [candidate] }), { status: 200 })),
    );
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

async function choose() {
    render(<MergeCustomerDialog merge={{ url: '/backoffice/clientes/2/fusionar', candidatesUrl: '/backoffice/clientes/2/candidatos' }} />);
    fireEvent.change(screen.getByLabelText('Buscar la ficha duplicada'), { target: { value: 'Gabriela' } });
    await act(async () => {
        await vi.advanceTimersByTimeAsync(1_000);
    });
    fireEvent.click(await screen.findByRole('button', { name: /Gabriela Suárez/ }));
}

it('cuenta en singular cuando mueve un solo apartado', async () => {
    await choose();

    expect(screen.getByText('Movería 1 apartado y 2 comprobantes.')).toBeInTheDocument();
});

it('pide el documento con un ejemplo que no se corta y un botón destructivo que se desactiva sin texto', async () => {
    await choose();

    const field = screen.getByLabelText('Para confirmar, escribe su documento');
    expect(field).toHaveAttribute('placeholder', 'V-24.567.890');
    expect(screen.getByText('V-24.567.890', { selector: '.whitespace-nowrap.font-semibold' })).toBeInTheDocument();

    const merge = screen.getByRole('button', { name: 'Fusionar las fichas' });
    expect(merge).toHaveClass('btn-error');
    expect(merge).toBeDisabled();

    fireEvent.change(field, { target: { value: 'V-24.567.890' } });
    expect(merge).toBeEnabled();
});
