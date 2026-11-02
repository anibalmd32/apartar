import Compose from '@/pages/backoffice/notifications/Compose';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

const postJson = vi.fn();

vi.mock('@/lib/json', () => ({
    JsonRequestError: class extends Error {},
    getJson: vi.fn(() => Promise.resolve({ customers: [] })),
    postJson: (...args: unknown[]) => postJson(...args),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/backoffice/avisos', props: { errors: {}, teamMember: { organization: { name: 'Boutique Mariela' } } } }),
    router: { on: () => () => undefined },
}));

vi.mock('@/layouts/backoffice-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));

beforeEach(() => {
    postJson.mockReset();
    postJson.mockResolvedValue({ recipients: 1, reachable: 1, unreachable: 0, queued: 0 });
});

const base = {
    available: true,
    limits: { title: 60, body: 180 },
    previewUrl: '/backoffice/avisos/vista-previa',
    sendUrl: '/backoffice/avisos',
    recipientsUrl: '/backoffice/avisos/clientes',
    hourlyLimit: 3,
};

it('llega con la ficha elegida y manda solo a ella', async () => {
    render(<Compose {...base} preselected={{ id: 7, name: 'Ana Pérez', detail: null, reachable: true }} history={[]} />);

    expect(within(screen.getByRole('list', { name: 'Elegidos' })).getByText('Ana Pérez')).toBeInTheDocument();
    expect(screen.getByText('Puedes enviar hasta 3 avisos por hora.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Hola' } });
    fireEvent.change(screen.getByLabelText('Texto'), { target: { value: 'Tu pedido llegó.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ver a cuántos llega' }));

    await waitFor(() =>
        expect(postJson).toHaveBeenCalledWith('/backoffice/avisos/vista-previa', expect.objectContaining({ customers: [7], title: 'Hola' })),
    );
});

it('sin nadie elegido no deja pedir las cifras', () => {
    render(<Compose {...base} preselected={{ id: 7, name: 'Ana Pérez', detail: null, reachable: false }} history={[]} />);

    fireEvent.click(screen.getByRole('button', { name: 'Quitar a Ana Pérez' }));
    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Hola' } });
    fireEvent.change(screen.getByLabelText('Texto'), { target: { value: 'Tu pedido llegó.' } });

    expect(screen.getByRole('button', { name: 'Ver a cuántos llega' })).toBeDisabled();
});

it('enseña el historial de envíos', () => {
    render(
        <Compose
            {...base}
            preselected={null}
            history={[
                {
                    id: 1,
                    title: 'Tu cuota vence pronto',
                    mode: 'segment',
                    recipients: 11,
                    queued: 8,
                    sender: 'Mariela R.',
                    sentAt: '2026-10-15T13:12:00Z',
                },
            ]}
        />,
    );

    expect(screen.getByText('Tu cuota vence pronto')).toBeInTheDocument();
    expect(screen.getByText(/Segmento · 8 de 11/)).toBeInTheDocument();
});

it('en el celular la vista previa queda justo debajo del texto mientras se escribe', () => {
    render(<Compose {...base} preselected={null} history={[]} />);

    fireEvent.change(screen.getByLabelText('Texto'), { target: { value: 'Tu pedido llegó.' } });

    const [inline, aside] = screen.getAllByRole('region', { name: 'Vista previa' });
    const text = screen.getByLabelText('Texto');

    expect(inline).toHaveClass('lg:hidden');
    expect(aside).toHaveClass('max-lg:hidden');
    expect(text.compareDocumentPosition(inline as Node) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByLabelText('Enlace (opcional)').compareDocumentPosition(inline as Node) & Node.DOCUMENT_POSITION_PRECEDING).toBeTruthy();
    expect(within(inline as HTMLElement).getByText('Tu pedido llegó.')).toBeInTheDocument();
});

it('en la selección manual la búsqueda va primero, los elegidos debajo y el teléfono con formato', async () => {
    const { getJson } = await import('@/lib/json');
    vi.mocked(getJson).mockResolvedValueOnce({ customers: [{ id: 9, name: 'Luis Rojas', detail: '04141234567', reachable: false }] });
    render(<Compose {...base} preselected={{ id: 7, name: 'Ana Pérez', detail: null, reachable: true }} history={[]} />);

    const search = screen.getByLabelText('Buscar clientes');
    const chosen = screen.getByRole('list', { name: 'Elegidos' });

    expect(search.compareDocumentPosition(chosen) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    fireEvent.change(search, { target: { value: 'Luis' } });

    expect(await screen.findByText('0414-1234567')).toBeInTheDocument();
    expect(screen.getByText('No recibe avisos')).toBeInTheDocument();
});

it('explica el historial vacío en vez de una línea suelta', () => {
    render(<Compose {...base} preselected={null} history={[]} />);

    expect(screen.getByText('Todavía no has enviado avisos')).toBeInTheDocument();
});
