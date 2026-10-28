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

it('separa un valor guardado con guiones y puntos', () => {
    render(<Harness initial="J-41234567-8" />);

    expect(screen.getByRole('button', { name: /Tipo de documento J/ })).toBeInTheDocument();
    expect(screen.getByLabelText('Cédula o RIF')).toHaveValue('412345678');
});
