import { suggestSku } from '@/features/products/lib/sku';
import { expect, it } from 'vitest';

it('arma el SKU con las primeras letras de las palabras que cuentan y el número que sigue', () => {
    expect(suggestSku('Vestido de lino blanco', 7)).toBe('VES-LIN-BLA-007');
    expect(suggestSku('Nevera Samsung 400 L', 42)).toBe('NEV-SAM-400-042');
});

it('quita los acentos y los símbolos', () => {
    expect(suggestSku('Teléfono «Ñandú» 5G', 1)).toBe('TEL-NAN-5G-001');
});

it('sin nombre propone un SKU genérico', () => {
    expect(suggestSku('   ', 12)).toBe('PRD-012');
    expect(suggestSku('de la', 0)).toBe('PRD-001');
});
