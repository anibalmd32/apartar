import { quantityHint } from '@/features/layaway/components/LayawayProductSection';
import { createBlocker, type CreateBlockerInput } from '@/features/layaway/lib/create-blocker';
import { describe, expect, it } from 'vitest';

const READY: CreateBlockerInput = {
    hasProduct: true,
    customerMode: 'existing',
    customer: '4',
    firstName: '',
    termsAccepted: false,
    windowMissing: false,
    withPayment: false,
    reading: false,
    paymentMissing: null,
};

describe('el botón de crear el apartado dice qué falta', () => {
    it.each<[Partial<CreateBlockerInput>, string | null]>([
        [{}, null],
        [{ hasProduct: false }, 'Elige el producto que se aparta.'],
        [{ customer: '' }, 'Busca y elige al cliente, o dalo de alta como cliente nuevo.'],
        [{ customerMode: 'new' }, 'Escribe el nombre del cliente nuevo.'],
        [{ customerMode: 'new', firstName: 'Pedro' }, 'Marca que el cliente aceptó las condiciones del apartado.'],
        [{ windowMissing: true }, 'Indica en cuántas horas o días vence el plazo para la inicial.'],
        [{ withPayment: true, reading: true }, 'Espera a que terminemos de validar el comprobante.'],
        [{ withPayment: true, paymentMissing: 'Elige el método del pago.' }, 'Elige el método del pago.'],
        [{ withPayment: false, paymentMissing: 'Elige el método del pago.' }, null],
    ])('%o', (overrides, reason) => {
        expect(createBlocker({ ...READY, ...overrides })).toBe(reason);
    });
});

describe('la cantidad explica su tope junto al stock', () => {
    it.each([
        [{ stock: 25, maxQuantity: 10 }, 'Hasta 10 unidades por apartado, aunque tienes 25 en stock.'],
        [{ stock: 3, maxQuantity: 3 }, 'Hasta 3 unidades: son las que quedan en stock.'],
        [{ stock: 1, maxQuantity: 1 }, 'Se aparta una unidad: es la única en stock.'],
    ])('%o', (limits, hint) => {
        expect(quantityHint({ id: 1, name: 'Nevera', sku: 'NEV', ...limits })).toBe(hint);
    });
});
