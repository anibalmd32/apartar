import { ApprovalForm } from '@/features/receipts/components/ApprovalForm';
import { PREVIEW, reviewProps } from '@/test/receipt-review-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, usePage } = vi.hoisted(() => ({ post: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage,
        Head: () => null,
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [defaults] = useState(initial);
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            const send = (url: string, options?: { onSuccess?: () => void }) => {
                post(url, data);
                options?.onSuccess?.();
            };

            return { data, setData, reset: () => setState(defaults), clearErrors: vi.fn(), errors: {}, processing: false, post: send };
        },
    };
});

function withQueries(node: ReactNode) {
    return render(<QueryClientProvider client={new QueryClient()}>{node}</QueryClientProvider>);
}

const EXCESS_PREVIEW = { ...PREVIEW, unallocated: '$10,00', unallocatedCents: 1_000 };

function renderApproval() {
    const props = reviewProps({ preview: EXCESS_PREVIEW });

    return withQueries(
        <ApprovalForm
            approval={props.approval!}
            receipt={props.receipt}
            remaining={props.layaway.remaining}
            rate={props.rate}
            initialPreview={EXCESS_PREVIEW}
            initialError={null}
        />,
    );
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    post.mockReset();
    usePage.mockReturnValue({ props: { errors: {} } });
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('al aprobar un comprobante con sobra se puede elegir el destino', async () => {
    renderApproval();

    fireEvent.click(screen.getByLabelText('Confirmo que apruebo con $10,00 sin asignar'));

    expect(screen.getByLabelText('¿A dónde va lo que sobra?')).toHaveValue('');
    expect(screen.queryByLabelText('Persona del equipo')).toBeNull();
});

it('elegir a una persona del equipo la pide y la manda', async () => {
    renderApproval();

    fireEvent.click(screen.getByLabelText('Confirmo que apruebo con $10,00 sin asignar'));
    fireEvent.change(screen.getByLabelText('¿A dónde va lo que sobra?'), { target: { value: 'team_member' } });

    expect(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Persona del equipo'), { target: { value: '7' } });

    expect(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' })).toBeEnabled();

    fireEvent.click(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' }));

    expect(post.mock.calls[0]?.[1]).toMatchObject({ surplus_destination: 'team_member', surplus_user_id: '7' });
});

it('«Otro» pide su nota antes de aprobar', async () => {
    renderApproval();

    fireEvent.click(screen.getByLabelText('Confirmo que apruebo con $10,00 sin asignar'));
    fireEvent.change(screen.getByLabelText('¿A dónde va lo que sobra?'), { target: { value: 'other' } });

    expect(screen.getByLabelText('Nota del destino (obligatoria)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Nota del destino (obligatoria)'), { target: { value: 'Un paquete de load' } });
    fireEvent.click(screen.getByRole('button', { name: 'Aprobar y aplicar el pago' }));

    expect(post.mock.calls[0]?.[1]).toMatchObject({ surplus_destination: 'other', surplus_note: 'Un paquete de load' });
});
