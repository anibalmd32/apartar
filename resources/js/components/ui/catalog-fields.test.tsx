import { BankField } from '@/components/ui/bank-field';
import { PhoneField } from '@/components/ui/phone-field';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage }));

beforeEach(() => {
    usePage.mockReturnValue({
        props: {
            catalogs: {
                banks: [
                    { id: 1, code: '0134', name: 'Banesco', logoUrl: '/images/banks/0134.png' },
                    { id: 2, code: '0102', name: 'Banco de Venezuela', logoUrl: '/images/banks/0102.png' },
                    { id: 3, code: '0116', name: 'BOD', logoUrl: null },
                ],
                mobilePrefixes: [
                    { id: 1, prefix: '0412', carrier: 'Digitel', logoUrl: '/images/carriers/digitel.svg' },
                    { id: 2, prefix: '0414', carrier: 'Movistar', logoUrl: '/images/carriers/movistar.png' },
                ],
            },
        },
    });
});

it('busca el banco por nombre o código y lo elige con su logo', () => {
    const onChange = vi.fn();
    render(<BankField label="Banco" value="" onChange={onChange} />);

    const input = screen.getByLabelText('Banco');
    act(() => fireEvent.change(input, { target: { value: 'bane' } }));

    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['Banesco0134']);

    act(() => fireEvent.keyDown(input, { key: 'ArrowDown' }));
    act(() => fireEvent.keyDown(input, { key: 'Enter' }));

    expect(onChange).toHaveBeenCalledWith('Banesco');
});

it('si el banco no está, ofrece usar lo escrito como otro banco', () => {
    const onChange = vi.fn();
    render(<BankField label="Banco" value="" onChange={onChange} />);

    const input = screen.getByLabelText('Banco');
    act(() => fireEvent.change(input, { target: { value: 'Bank of America' } }));

    expect(screen.getByRole('option', { name: 'Otro banco: «Bank of America»' })).toBeInTheDocument();

    act(() => fireEvent.keyDown(input, { key: 'ArrowDown' }));
    act(() => fireEvent.keyDown(input, { key: 'Enter' }));

    expect(onChange).toHaveBeenCalledWith('Bank of America');
});

it('arma el teléfono con el prefijo elegido y siete dígitos', () => {
    const onChange = vi.fn();
    render(<PhoneField label="Teléfono celular" value="" onChange={onChange} />);

    act(() => fireEvent.click(screen.getByRole('button', { name: 'Prefijo' })));
    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual(['0412Digitel', '0414Movistar']);
    act(() => fireEvent.click(screen.getByRole('option', { name: /0414/ })));

    act(() => fireEvent.change(screen.getByLabelText('Teléfono celular'), { target: { value: '12-34-567-89' } }));

    expect(screen.getByLabelText('Teléfono celular')).toHaveValue('1234567');
    expect(onChange).toHaveBeenLastCalledWith('04141234567');
});

it('separa un teléfono guardado en prefijo y número', () => {
    render(<PhoneField label="Teléfono celular" value="+584121234567" onChange={vi.fn()} />);

    expect(screen.getByRole('button', { name: 'Prefijo 0412 (Digitel)' })).toBeInTheDocument();
    expect(screen.getByLabelText('Teléfono celular')).toHaveValue('1234567');
});
