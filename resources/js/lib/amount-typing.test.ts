import { formatAmountTyping, RATE_DECIMALS } from '@/lib/amount-typing';
import { describe, expect, it } from 'vitest';

describe('el monto se escribe al estilo venezolano', () => {
    it.each([
        ['1234', '1.234'],
        ['1234567', '1.234.567'],
        ['1.2345', '12.345'],
        ['1.234,5', '1.234,5'],
        ['1234,567', '1.234,56'],
        ['1.234.', '1.234,'],
        ['80.', '80,'],
        [',5', '0,5'],
        ['007', '7'],
        ['1.23', '123'],
        ['Bs 1.000,00', '1.000,00'],
        ['', ''],
    ])('%s queda %s', (typed, shown) => {
        expect(formatAmountTyping(typed)).toBe(shown);
    });

    it('la tasa admite más decimales', () => {
        expect(formatAmountTyping('36,12345678', RATE_DECIMALS)).toBe('36,12345678');
        expect(formatAmountTyping('36,123456789', RATE_DECIMALS)).toBe('36,12345678');
    });
});
