import { ProductCopyAssistant } from '@/features/products/components/ProductCopyAssistant';
import { type CopywriterInput } from '@/features/products/hooks/useCopywriter';
import { useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { type AiAvailability } from '@/types/product';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

const AVAILABLE: AiAvailability = { available: true, remaining: 30 };

const photo = new File(['x'], 'silla.png', { type: 'image/png' });

function input(overrides: Partial<CopywriterInput> = {}): CopywriterInput {
    return { name: 'Silla', categoryName: 'Hogar', description: '', photos: [photo], productId: null, ...overrides };
}

function respond(status: number, body: unknown) {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function Harness({ ai = AVAILABLE, initial = '' }: { ai?: AiAvailability; initial?: string }) {
    const [description, setDescription] = useState(initial);
    const sheet = useSpecificationRows(null);

    return (
        <>
            <ProductCopyAssistant
                ai={ai}
                input={input({ description })}
                hasStoredPhotos={false}
                onApply={(html, characteristics) => {
                    setDescription(html);
                    sheet.append(characteristics);
                }}
            />
            <output data-testid="description">{description}</output>
            <ul data-testid="rows">
                {sheet.rows.map((row) => (
                    <li key={row.key}>{row.text}</li>
                ))}
            </ul>
        </>
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

it('sin la IA configurada deja los dos botones deshabilitados con el motivo', () => {
    const fetchMock = respond(200, {});
    render(<Harness ai={{ available: false, remaining: null }} initial="<p>Hola</p>" />);

    expect(screen.getByRole('button', { name: 'Sugerir con IA' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Mejorar con IA' })).toBeDisabled();

    fireEvent.click(screen.getAllByRole('button', { name: 'Por qué no está disponible' })[0]!);
    expect(screen.getByRole('tooltip')).toHaveTextContent('La IA todavía no está configurada.');
    expect(screen.queryByText(/sugerencias hoy/)).not.toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
});

it('«Mejorar con IA» espera a que la descripción tenga texto', () => {
    render(<Harness initial="<p></p>" />);

    expect(screen.getByRole('button', { name: 'Sugerir con IA' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Mejorar con IA' })).toBeDisabled();
    expect(screen.getByText('Te quedan 30 sugerencias hoy')).toBeInTheDocument();
});

it('«Sugerir con IA» manda fotos y datos, y la sugerencia aceptada llena la descripción y las características', async () => {
    const fetchMock = respond(200, {
        description: '<p>Una silla <strong>cómoda</strong>.</p>',
        characteristics: ['Patas de madera', 'Asiento acolchado'],
        remaining: 29,
    });
    render(<Harness />);

    fireEvent.click(screen.getByRole('button', { name: 'Sugerir con IA' }));

    const dialog = await screen.findByRole('dialog');
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    const body = init.body as FormData;

    expect(url).toBe('/backoffice/productos/ia/sugerencia');
    expect(body.get('mode')).toBe('suggest');
    expect(body.get('name')).toBe('Silla');
    expect(body.get('category_name')).toBe('Hogar');
    expect(body.getAll('images[]')).toHaveLength(1);
    expect(body.get('product_id')).toBeNull();

    expect(within(dialog).getByTestId('copy-suggestion').innerHTML).toBe('<p>Una silla <strong>cómoda</strong>.</p>');
    expect(within(dialog).getByText('Te quedan 29 sugerencias hoy')).toBeInTheDocument();
    expect(within(dialog).getByRole('checkbox', { name: 'Patas de madera' })).toBeChecked();

    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Asiento acolchado' }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Usar sugerencia' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getByTestId('description')).toHaveTextContent('<p>Una silla <strong>cómoda</strong>.</p>');
    expect(
        within(screen.getByTestId('rows'))
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual(['Patas de madera']);
});

it('«Descartar» cierra la sugerencia sin tocar nada', async () => {
    respond(200, { description: '<p>Otra</p>', characteristics: ['Rojo'], remaining: 5 });
    render(<Harness initial="<p>Mía</p>" />);

    fireEvent.click(screen.getByRole('button', { name: 'Mejorar con IA' }));
    fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Descartar' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(screen.getByTestId('description')).toHaveTextContent('<p>Mía</p>');
    expect(screen.getByText('Te quedan 5 sugerencias hoy')).toBeInTheDocument();
});

it('muestra el mensaje del servidor y bloquea los botones al agotar el cupo', async () => {
    respond(429, { message: 'Ya usaste las 30 sugerencias de hoy. Vuelve mañana.', remaining: 0 });
    render(<Harness />);

    fireEvent.click(screen.getByRole('button', { name: 'Sugerir con IA' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Ya usaste las 30 sugerencias de hoy. Vuelve mañana.');
    expect(screen.getByRole('button', { name: 'Sugerir con IA' })).toBeDisabled();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('un fallo del proveedor se muestra sin abrir el diálogo', async () => {
    respond(503, { message: 'La IA no respondió. Intenta de nuevo en un momento.', remaining: 30 });
    render(<Harness />);

    fireEvent.click(screen.getByRole('button', { name: 'Sugerir con IA' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('La IA no respondió. Intenta de nuevo en un momento.');
    expect(screen.getByRole('button', { name: 'Sugerir con IA' })).toBeEnabled();
});
