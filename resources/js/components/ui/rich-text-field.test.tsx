import { RichTextField } from '@/components/ui/rich-text-field';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { beforeAll, beforeEach, expect, it } from 'vitest';

let latest = '';
let replace: (html: string) => void = () => {};

function Harness({ initial = '', error }: { initial?: string; error?: string }) {
    const [value, setValue] = useState(initial);
    replace = setValue;

    return (
        <RichTextField
            label="Descripción"
            value={value}
            onChange={(next) => {
                latest = next;
                setValue(next);
            }}
            error={error}
            hint="Lo que lee quien decide."
            maxLength={10}
        />
    );
}

async function editor(): Promise<HTMLElement> {
    return screen.findByRole('textbox', { name: 'Descripción' });
}

beforeAll(() => {
    Range.prototype.getBoundingClientRect = () => new DOMRect();
    Range.prototype.getClientRects = () => [] as unknown as DOMRectList;
    document.elementFromPoint = () => null;
});

beforeEach(() => {
    latest = '';
    replace = () => {};
});

it('muestra la barra con sus botones en español y el contenido inicial', async () => {
    render(<Harness initial="<p>Taladro <strong>percutor</strong></p>" />);

    const field = await editor();

    expect(field.innerHTML).toContain('<strong>percutor</strong>');
    expect(screen.getByRole('toolbar', { name: 'Formato del texto' })).toBeInTheDocument();

    for (const name of ['Negrita', 'Cursiva', 'Lista con viñetas', 'Lista numerada', 'Deshacer', 'Rehacer']) {
        expect(screen.getByRole('button', { name })).toBeInTheDocument();
    }

    expect(screen.getByText('Lo que lee quien decide.')).toBeInTheDocument();
    expect(await screen.findByText('16 / 10')).toBeInTheDocument();
});

it('cuenta solo el texto y marca cuando pasa del límite', async () => {
    render(<Harness initial="<p><strong>abcdefghijk</strong></p>" />);

    await editor();

    expect(await screen.findByText('11 / 10')).toHaveClass('text-error');
});

it('aplica el formato desde la barra y avisa con el HTML', async () => {
    render(<Harness initial="<p>Hola</p>" />);

    const field = await editor();

    act(() => {
        field.focus();
    });
    fireEvent.click(screen.getByRole('button', { name: 'Lista con viñetas' }));

    await waitFor(() => expect(latest).toBe('<ul><li><p>Hola</p></li></ul>'));
    expect(screen.getByRole('button', { name: 'Lista con viñetas' })).toHaveAttribute('aria-pressed', 'true');

    fireEvent.click(screen.getByRole('button', { name: 'Deshacer' }));

    await waitFor(() => expect(latest).toBe('<p>Hola</p>'));
});

it('reemplaza el contenido cuando el valor cambia desde fuera', async () => {
    render(<Harness initial="<p>Antes</p>" />);

    const field = await editor();

    act(() => replace('<p>Sugerida por el asistente</p><ol><li><p>Uno</p></li></ol>'));

    await waitFor(() => expect(field.innerHTML).toContain('Sugerida por el asistente'));
    expect(field.querySelector('ol li')).toHaveTextContent('Uno');
    expect(latest).toBe('');
});

it('enlaza el error con el campo', async () => {
    render(<Harness error="Escribe la descripción." />);

    const field = await editor();

    expect(field).toHaveAttribute('aria-invalid', 'true');
    expect(field.getAttribute('aria-describedby')).toContain(screen.getByRole('alert').id);
});
