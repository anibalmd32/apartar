import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { describe, expect, it } from 'vitest';
import cases from './identity-format.cases.json';

/**
 * `REQ-IDN-007`: la máscara da forma y no rechaza nunca.
 *
 * La tabla de casos es la misma que recorre `IdentityFormatCasesTest.php`
 * contra `formatted()` del servidor: para toda escritura completa, la máscara
 * y el servidor enseñan lo mismo.
 */
describe('máscara del documento', () => {
    it.each(cases.documents)('enseña $input como el servidor: $formatted', ({ input, formatted }) => {
        expect(formatDocumentNumber(input)).toBe(formatted);
    });

    it('conserva los ceros a la izquierda (RB-7)', () => {
        expect(formatDocumentNumber('v0012345')).toBe('V-0.012.345');
        expect(formatDocumentNumber('00123456')).toBe('00.123.456');
    });

    it('no inventa la letra: sin ella agrupa los dígitos y la V la pone el servidor', () => {
        expect(formatDocumentNumber('12345678')).toBe('12.345.678');
    });

    it('va dando forma mientras se teclea', () => {
        expect(formatDocumentNumber('v')).toBe('V');
        expect(formatDocumentNumber('V1')).toBe('V-1');
        expect(formatDocumentNumber('V1234')).toBe('V-1.234');
        expect(formatDocumentNumber('J12345678')).toBe('J-12345678');
        expect(formatDocumentNumber('J123456789')).toBe('J-12345678-9');
    });

    /**
     * Lo que no tiene forma de documento vuelve tal cual: lo rechaza el
     * servidor, con su motivo.
     */
    it.each(['P-123', '12,345', 'VJ12345678', 'ñ', ''])('no rechaza ni recorta %j', (raw) => {
        const masked = formatDocumentNumber(raw);

        expect(() => formatDocumentNumber(raw)).not.toThrow();
        expect(masked.replace(/[^A-Za-z0-9ñ,]/g, '').toUpperCase()).toBe(raw.replace(/[^A-Za-z0-9ñ,]/g, '').toUpperCase());
    });

    it('no limita la longitud: una cédula de diez dígitos la rechaza PHP con el rango', () => {
        expect(formatDocumentNumber('V1234567890')).toBe('V-1.234.567.890');
    });
});

describe('máscara del teléfono', () => {
    it.each(cases.phones)('enseña $input como el servidor: $formatted', ({ input, formatted }) => {
        expect(formatPhone(input)).toBe(formatted);
    });

    it('da forma al internacional sin quitarle el +58', () => {
        expect(formatPhone('+584141234567')).toBe('+58 414-1234567');
        expect(formatPhone('+58 414 123 45 67')).toBe('+58 414-1234567');
    });

    it('da forma al nacional sin cero', () => {
        expect(formatPhone('4141234567')).toBe('414-1234567');
    });

    /**
     * Ni el código de operadora ni la longitud ni el país los decide la
     * máscara: `+1`, `0500` y un número corto pasan con sus dígitos.
     */
    it.each(['+1 305 555 0199', '05001234567', '0414', 'llámame', ''])('no rechaza %j', (raw) => {
        expect(() => formatPhone(raw)).not.toThrow();
        expect(formatPhone(raw).replace(/\D/g, '')).toBe(raw.replace(/\D/g, ''));
    });
});
