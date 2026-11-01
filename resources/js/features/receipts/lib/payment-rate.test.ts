import { appliedRateLine, rateHint } from '@/features/receipts/lib/payment-rate';
import { MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { PREVIEW } from '@/test/receipt-review-fixtures';
import { describe, expect, it } from 'vitest';

describe('la pista de la tasa sigue la fecha del pago', () => {
    const today = OPTIONS.paidAt.max;

    it('hoy dice la vigente', () => {
        expect(rateHint(OPTIONS, today, null)).toMatch(/^Vacía usa la vigente/);
    });

    it('otro día dice la tasa de ese día, cuando la vista previa ya la trae', () => {
        expect(rateHint(OPTIONS, '2026-01-17', { ...PREVIEW, rate: '158.50000000', rateSource: 'bcv', rateSourceLabel: 'BCV' })).toBe(
            'Vacía usa la del 17/01/2026: 158,50 (BCV).',
        );
    });

    it('otro día sin vista previa promete la oficial de ese día', () => {
        expect(rateHint(OPTIONS, '2026-01-17', null)).toBe('Vacía usa la tasa oficial del 17/01/2026.');
    });
});

describe('la vista previa dice a qué tasa se registra el pago', () => {
    const bcv = { ...PREVIEW, rate: '158.50000000', rateSource: 'bcv' as const, rateSourceLabel: 'BCV' };

    it('nombra la fuente y el día del pago', () => {
        expect(appliedRateLine(OPTIONS, '2026-01-17', bcv)).toBe('Se registra a 158,50 Bs por dólar (BCV, la del 17/01/2026).');
        expect(appliedRateLine(OPTIONS, OPTIONS.paidAt.max, bcv)).toBe('Se registra a 158,50 Bs por dólar (BCV, la vigente).');
    });

    it('con la tasa escrita a mano lo dice así', () => {
        expect(appliedRateLine(OPTIONS, '2026-01-17', { ...bcv, rateSource: 'manual', rateSourceLabel: 'Manual' })).toBe(
            'Se registra a 158,50 Bs por dólar, la tasa que escribiste.',
        );
    });
});
