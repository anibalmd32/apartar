import { InboxFilters } from '@/features/receipts/components/InboxFilters';
import { type ReceiptInboxFilters, type ReceiptInboxProps } from '@/types/receipt';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const EMPTY: ReceiptInboxFilters = { status: null, method: null, bank: null, account: null, from: null, to: null, cancelled: null };

const OPTIONS = {
    statuses: [{ value: 'pending_review', label: 'En revisión' }],
    methods: [
        { value: 'pago_movil', label: 'Pago móvil' },
        { value: 'zelle', label: 'Zelle' },
    ],
    banks: ['Banesco', 'Mercantil'],
    paymentAccounts: [],
} as unknown as ReceiptInboxProps['options'];

function setup(filters: Partial<ReceiptInboxFilters> = {}) {
    const onChange = vi.fn();
    const onChangeMany = vi.fn();
    render(<InboxFilters filters={{ ...EMPTY, ...filters }} options={OPTIONS} onChange={onChange} onChangeMany={onChangeMany} />);

    return { onChange, onChangeMany };
}

it('en el celular los filtros van en una hoja y se aplican juntos al tocar Aplicar', () => {
    const { onChange, onChangeMany } = setup();

    fireEvent.click(screen.getByRole('button', { name: 'Filtros' }));
    const sheet = within(screen.getByRole('dialog', { name: 'Filtros' }));

    fireEvent.change(sheet.getByLabelText('Método'), { target: { value: 'zelle' } });
    fireEvent.change(sheet.getByLabelText('Banco'), { target: { value: 'Banesco' } });
    fireEvent.click(sheet.getByRole('checkbox', { name: 'Incluir los de apartados cancelados' }));

    expect(onChange).not.toHaveBeenCalled();
    expect(onChangeMany).not.toHaveBeenCalled();

    fireEvent.click(sheet.getByRole('button', { name: 'Aplicar' }));

    expect(onChangeMany).toHaveBeenCalledWith({ method: 'zelle', bank: 'Banesco', cancelled: '1' });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('Limpiar quita todos los filtros de la hoja de una vez', () => {
    const { onChangeMany } = setup({ method: 'zelle', from: '2026-10-01' });

    fireEvent.click(screen.getByRole('button', { name: 'Filtros, 2 aplicados' }));
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Limpiar' }));

    expect(onChangeMany).toHaveBeenCalledWith({ method: null, bank: null, account: null, from: null, to: null, cancelled: null });
});

it('los filtros aplicados se ven como chips que se quitan con un toque', () => {
    const { onChange } = setup({ method: 'pago_movil', from: '2026-10-01', status: 'pending_review' });

    const chips = within(screen.getByRole('list', { name: 'Filtros aplicados' }));
    expect(chips.getAllByRole('button').map((chip) => chip.textContent)).toEqual(['Método: Pago móvil', 'Llegó desde 01/10/2026']);

    fireEvent.click(chips.getByRole('button', { name: 'Quitar filtro: Método: Pago móvil' }));

    expect(onChange).toHaveBeenCalledWith('method', null);
});

it('en el celular los selects de la fila ocupan todo el ancho y en la computadora vuelven a su tamaño', () => {
    setup();

    const select = screen.getByLabelText('Banco');

    expect(select).toHaveClass('max-lg:w-full', 'lg:w-auto', 'max-lg:appearance-none');
    expect(select.closest('fieldset')).toHaveClass('max-lg:w-full');
});
