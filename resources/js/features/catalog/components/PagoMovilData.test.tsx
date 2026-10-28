import { PagoMovilData, pagoMovilText } from '@/features/catalog/components/PagoMovilData';
import { type PaymentAccountView } from '@/types/payment';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

const account: PaymentAccountView = {
    id: 1,
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    identifierLabel: 'Teléfono',
    bank: 'Mercantil',
    bankLogoUrl: null,
    bankCode: '0105',
    holderName: 'Anibal Mendoza',
    holderDocument: 'V-26.777.308',
    identifier: '0412-4911455',
    instructions: null,
    isActive: true,
    position: 1,
};

const writeText = vi.fn();

beforeEach(() => {
    writeText.mockReset().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
});

it('copia teléfono, cédula y código del banco en ese orden, y nada más', () => {
    expect(pagoMovilText(account)).toBe('04124911455\nV26777308\n0105');
});

it('copia cada dato por separado', async () => {
    render(<PagoMovilData account={account} />);

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Copiar cédula o rif del pago móvil' })));
    expect(writeText).toHaveBeenLastCalledWith('V26777308');

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Copiar banco del pago móvil' })));
    expect(writeText).toHaveBeenLastCalledWith('0105');
});

it('copia todos los datos juntos', async () => {
    render(<PagoMovilData account={account} />);

    await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Copiar todos los datos' })));

    expect(writeText).toHaveBeenCalledWith('04124911455\nV26777308\n0105');
    expect(screen.getByRole('button', { name: 'Datos copiados' })).toBeInTheDocument();
});

it('sin banco del catálogo no copia un código vacío', () => {
    expect(pagoMovilText({ ...account, bankCode: null })).toBe('04124911455\nV26777308');
});
