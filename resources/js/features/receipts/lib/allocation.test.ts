import { allocationProblem, centsToInput, formatCents, parseCents } from '@/features/receipts/lib/allocation';
import { expect, it } from 'vitest';

it('lee importes con punto y hasta dos decimales, sin coma flotante', () => {
    expect(parseCents('30')).toBe(3_000);
    expect(parseCents('30.5')).toBe(3_050);
    expect(parseCents(' 0.07 ')).toBe(7);
    expect(parseCents('')).toBe(0);
    expect(parseCents('30,50')).toBeNull();
    expect(parseCents('30.505')).toBeNull();
    expect(parseCents('-1')).toBeNull();
    expect((parseCents('0.1') ?? 0) + (parseCents('0.2') ?? 0)).toBe(parseCents('0.3'));
});

it('escribe como el servidor', () => {
    expect(formatCents(123_456)).toBe('$1.234,56');
    expect(formatCents(5)).toBe('$0,05');
    expect(centsToInput(3_000)).toBe('30.00');
});

it('dice por qué no se puede enviar: importe, sobrepago o suma', () => {
    const row = (number: number, text: string, roomCents = 3_000) => ({ installmentId: number, number, roomCents, text });

    expect(allocationProblem([row(1, '30.00'), row(2, '30.00')], 6_000)).toBeNull();
    expect(allocationProblem([row(1, '30,00')], 3_000)).toContain('cuota 1 no es válido');
    expect(allocationProblem([row(1, '45.00', 1_500)], 4_500)).toBe('La cuota 1 admite como mucho $15,00 de este pago.');
    expect(allocationProblem([row(1, '30.00'), row(2, '20.00')], 6_000)).toBe('Faltan $10,00: el reparto tiene que sumar $60,00.');
    expect(allocationProblem([row(1, '30.00'), row(2, '30.00'), row(3, '5')], 6_000)).toBe('Sobran $5,00: el reparto tiene que sumar $60,00.');
});
