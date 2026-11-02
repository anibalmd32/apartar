import { describe, expect, it } from 'vitest';
import { usageLabel, usageValue } from './usage';

describe('consumo del plan', () => {
    it('muestra el almacenamiento en GB cuando pasa de 1024 MB', () => {
        expect(usageValue('storage_mb', 1, 5120)).toBe('1 MB de 5 GB');
        expect(usageValue('storage_mb', 1536, 5120)).toBe('1,5 GB de 5 GB');
    });

    it('deja el almacenamiento en MB cuando es poco', () => {
        expect(usageValue('storage_mb', 300, 500)).toBe('300 MB de 500 MB');
        expect(usageValue('storage_mb', 2048, null)).toBe('2 GB de ilimitado');
    });

    it('llama Almacenamiento a la métrica de MB y deja las demás como vienen', () => {
        expect(usageLabel('storage_mb', 'MB de almacenamiento')).toBe('Almacenamiento');
        expect(usageLabel('users', 'Usuarios')).toBe('Usuarios');
        expect(usageValue('users', 2, 5)).toBe('2 de 5');
    });
});
