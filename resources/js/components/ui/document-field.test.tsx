import { DocumentField } from '@/components/ui/document-field';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

const catalogs = {
    banks: [],
    mobilePrefixes: [],
    documentPrefixes: [
        { id: 1, code: 'V', name: 'Venezolano' },
        { id: 2, code: 'E', name: 'Extranjero' },
        { id: 3, code: 'J', name: 'Jurídico' },
    ],
};

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { catalogs } }) }));

let latest = '';

function Harness({ initial = '' }: { initial?: string }) {
    const [value, setValue] = useState(initial);

    return (
        <DocumentField
            label="Cédula o RIF"
            name="document"
            value={value}
            onChange={(next) => {
                latest = next;
                setValue(next);
            }}
        />
    );
}

beforeEach(() => {
    latest = '';
});

it('arranca en V y une el prefijo con los dígitos', () => {
    render(<Harness />);

    expect(screen.getByRole('button', { name: /Tipo de documento V/ })).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Cédula o RIF'), { target: { value: '26.777.308' } });

    expect(latest).toBe('V26777308');
});

it('cambia el prefijo desde el catálogo', () => {
    render(<Harness initial="V26777308" />);

    act(() => fireEvent.click(screen.getByRole('button', { name: /Tipo de documento V/ })));
    act(() => fireEvent.click(screen.getByRole('option', { name: /Jurídico/ })));

    expect(latest).toBe('J26777308');
});

function paste(text: string) {
    const input = screen.getByLabelText<HTMLInputElement>('Cédula o RIF');
    const allowed = input.maxLength >= 0 ? text.slice(0, input.maxLength) : text;

    fireEvent.change(input, { target: { value: allowed } });
}

it.each([
    ['V-21.234.567', 'V', '21234567'],
    ['V 21.234.567', 'V', '21234567'],
    ['e 81234567', 'E', '81234567'],
    ['j-41234567-8', 'J', '412345678'],
    ['21.234.567', 'V', '21234567'],
])('al pegar "%s" elige el prefijo %s y deja solo los dígitos', (text, prefix, digits) => {
    render(<Harness />);

    paste(text);

    expect(latest).toBe(`${prefix}${digits}`);
    expect(screen.getByRole('button', { name: new RegExp(`Tipo de documento ${prefix}`) })).toBeInTheDocument();
    expect(screen.getByLabelText('Cédula o RIF')).toHaveValue(digits);
});

it('conserva el prefijo elegido cuando se pegan solo números', () => {
    render(<Harness initial="E81234567" />);

    paste('12.345.678');

    expect(latest).toBe('E12345678');
});

it('limita a diez dígitos lo que queda después de limpiar', () => {
    render(<Harness />);

    paste('V-1234.5678.9012');

    expect(latest).toBe('V1234567890');
});

it('separa un valor guardado con guiones y puntos', () => {
    render(<Harness initial="J-41234567-8" />);

    expect(screen.getByRole('button', { name: /Tipo de documento J/ })).toBeInTheDocument();
    expect(screen.getByLabelText('Cédula o RIF')).toHaveValue('412345678');
});
