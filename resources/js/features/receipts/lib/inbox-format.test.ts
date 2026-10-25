import { formatAge, formatDay, formatDeclaredAmount, formatRate } from '@/features/receipts/lib/inbox-format';
import { expect, it } from 'vitest';

it('escribe el importe declarado con su moneda, como el resto de la aplicación', () => {
    expect(formatDeclaredAmount('850.00', 'USD')).toBe('$850,00');
    expect(formatDeclaredAmount('1275.5', 'VES')).toBe('Bs. 1.275,50');
    expect(formatDeclaredAmount('1234567.89', 'USD')).toBe('$1.234.567,89');
});

it('no pierde cifras con los catorce enteros que admite el importe', () => {
    expect(formatDeclaredAmount('12345678901234.56', 'VES')).toBe('Bs. 12.345.678.901.234,56');
});

it('dice la antigüedad en horas hasta el umbral de atrasado, redondeando hacia abajo', () => {
    const now = Date.parse('2026-10-07T12:00:00-04:00');

    expect(formatAge('2026-10-07T11:55:00-04:00', now)).toBe('hace 5 min');
    expect(formatAge('2026-10-07T09:00:00-04:00', now)).toBe('hace 3 h');
    expect(formatAge('2026-10-05T12:01:00-04:00', now)).toBe('hace 47 h');
    expect(formatAge('2026-10-05T12:00:00-04:00', now)).toBe('hace 2 d');
    expect(formatAge('2026-10-06T10:00:00-04:00', now)).toBe('hace 26 h');
    expect(formatAge('2026-10-07T12:00:30-04:00', now)).toBe('hace 0 min');
});

it('escribe el día declarado sin pasar por ninguna zona horaria', () => {
    expect(formatDay('2026-10-06')).toBe('06/10/2026');
});

it('escribe la tasa como se lee, sin los ceros de relleno de la columna', () => {
    expect(formatRate('42.50000000')).toBe('42,50');
    expect(formatRate('36.12345679')).toBe('36,12345679');
    expect(formatRate('1.00000000')).toBe('1,00');
    expect(formatRate('1234.5')).toBe('1.234,50');
});
