import { appliedRateLine } from '@/features/receipts/lib/payment-rate';
import { MANUAL_PAYMENT_OPTIONS as OPTIONS } from '@/test/manual-payment-fixtures';
import { PREVIEW } from '@/test/receipt-review-fixtures';
import { describe, expect, it } from 'vitest';

describe('la vista previa dice cuánto equivale en dólares y a qué tasa', () => {
    const bcv = { ...PREVIEW, amount: '$133,67', rate: '158.50000000', rateSource: 'bcv' as const, rateSourceLabel: 'BCV' };

    it('nombra la fuente y el día del pago', () => {
        expect(appliedRateLine(OPTIONS, '2026-01-17', bcv)).toBe('Equivale a $133,67: a 158,50 Bs por dólar (BCV, la del 17/01/2026).');
        expect(appliedRateLine(OPTIONS, OPTIONS.paidAt.max, bcv)).toBe('Equivale a $133,67: a 158,50 Bs por dólar (BCV, la vigente).');
    });

    it('con la tasa escrita a mano lo dice así', () => {
        expect(appliedRateLine(OPTIONS, '2026-01-17', { ...bcv, rateSource: 'manual', rateSourceLabel: 'Manual' })).toBe(
            'Equivale a $133,67: a 158,50 Bs por dólar, la tasa que escribiste.',
        );
    });
});
