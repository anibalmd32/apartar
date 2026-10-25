import { PREVIEW_DEBOUNCE_MS } from '@/features/receipts/hooks/useApprovalPreview';
import ReceiptReview from '@/pages/backoffice/receipts/Review';
import { PREVIEW, reviewProps as props } from '@/test/receipt-review-fixtures';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { post, formErrors } = vi.hoisted(() => ({ post: vi.fn(), formErrors: { current: {} as Record<string, string> } }));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        usePage: () => ({ props: { errors: {}, currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } }),
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = useState(initial);
            const setData = (key: keyof T | ((previous: T) => T), value?: unknown) =>
                setState((previous) => (typeof key === 'function' ? key(previous) : { ...previous, [key]: value }));

            return { data, setData, errors: formErrors.current, processing: false, post: (url: string) => post(url, data) };
        },
    };
});

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <QueryClientProvider client={new QueryClient()}>{children}</QueryClientProvider>,
}));

const OLD = { token: 'bcv@40.00000000@1753000000', days: 45, sourceLabel: 'BCV' };
const APPROVE = 'Aprobar y aplicar el pago';
const CONFIRM = 'Confirmo que convierto con la tasa de hace 45 días';

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
    post.mockReset();
    formErrors.current = {};
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

async function settle() {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(PREVIEW_DEBOUNCE_MS + 10);
    });
}

it('no aprueba hasta confirmar la tasa vieja, y manda esa tasa', () => {
    render(<ReceiptReview {...props({ preview: { ...PREVIEW, staleRate: OLD } })} />);

    expect(screen.getByText(/La tasa BCV se publicó hace 45 días/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: APPROVE })).toBeDisabled();

    fireEvent.click(screen.getByLabelText(CONFIRM));
    fireEvent.click(screen.getByRole('button', { name: APPROVE }));

    expect(post.mock.calls[0]?.[1]).toMatchObject({ confirm_stale_rate: OLD.token });
});

it('si la tasa cambia, hay que volver a confirmarla', async () => {
    vi.mocked(fetch).mockResolvedValue(
        new Response(JSON.stringify({ preview: { ...PREVIEW, staleRate: { ...OLD, token: 'bcv@41.00000000@1753000000' } } })),
    );
    render(<ReceiptReview {...props({ preview: { ...PREVIEW, staleRate: OLD } })} />);

    fireEvent.click(screen.getByLabelText(CONFIRM));
    expect(screen.getByRole('button', { name: APPROVE })).toBeEnabled();

    fireEvent.change(screen.getByLabelText('Importe recibido'), { target: { value: '1200.00' } });
    await settle();

    expect(await screen.findByLabelText(CONFIRM)).not.toBeChecked();
    expect(screen.getByRole('button', { name: APPROVE })).toBeDisabled();
});

it('si el servidor pide confirmar una tasa que la vista previa no traía, la vuelve a pedir', async () => {
    formErrors.current = { confirm_stale_rate: 'La tasa BCV que se usaría se publicó hace 45 días.' };
    vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify({ preview: { ...PREVIEW, staleRate: OLD } })));
    render(<ReceiptReview {...props()} />);

    await settle();

    expect(vi.mocked(fetch)).toHaveBeenCalledTimes(1);
    expect(await screen.findByLabelText(CONFIRM)).toBeInTheDocument();
    expect(screen.getByText('La tasa BCV que se usaría se publicó hace 45 días.')).toBeInTheDocument();
});
