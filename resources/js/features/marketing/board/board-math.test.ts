import { caracasToday, formatBs, formatRate, formatUsd, layawaySum, longDay, shortDay } from '@/features/marketing/board/board-math';
import { describe, expect, it } from 'vitest';

describe('la cuenta de ejemplo de la pizarra', () => {
    it('con 3 cuotas reparte $36 en cuotas de $12 y, con la inicial y la primera pagadas, resta $24', () => {
        const sum = layawaySum(3, '2026-11-04');

        expect(sum.rows.map((row) => [row.label, row.cents, row.paid])).toEqual([
            ['Inicial', 1200, true],
            ['Cuota 1', 1200, true],
            ['Cuota 2', 1200, false],
            ['Cuota 3', 1200, false],
        ]);
        expect(sum.remainingCents).toBe(2400);
    });

    it('cambiar el número de cuotas cambia lo que resta', () => {
        expect(layawaySum(2, '2026-11-04').remainingCents).toBe(1800);
        expect(layawaySum(4, '2026-11-04').remainingCents).toBe(2700);
    });

    it('las cuotas vencen cada 15 días y la primera ya pasó', () => {
        const dues = layawaySum(3, '2026-11-04').rows.map((row) => row.due);

        expect(dues).toEqual([null, '2026-11-04', '2026-11-19', '2026-12-04']);
    });
});

describe('los montos se escriben al estilo venezolano', () => {
    it('dólares con coma decimal y punto de miles', () => {
        expect(formatUsd(4800)).toBe('$48,00');
        expect(formatUsd(123456)).toBe('$1.234,56');
    });

    it('bolívares al multiplicar por la tasa', () => {
        expect(formatBs(4800, '889.57540000')).toBe('Bs. 42.699,62');
        expect(formatRate('889.57540000')).toBe('Bs. 889,58');
    });
});

describe('las fechas se escriben como en la pizarra', () => {
    it('día largo y corto en español', () => {
        expect(longDay('2026-11-04')).toBe('miércoles 4 de noviembre');
        expect(shortDay('2026-12-04')).toBe('4 dic');
    });

    it('el día de hoy es el de Caracas, no el de UTC', () => {
        expect(caracasToday(new Date('2026-11-05T02:30:00Z'))).toBe('2026-11-04');
    });
});
