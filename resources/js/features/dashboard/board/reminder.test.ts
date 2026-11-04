import { reminderText, reminderUrl } from '@/features/dashboard/board/reminder';
import { formatBsCents, formatUsdCents } from '@/lib/cents';
import { expect, it } from 'vitest';

const base = { name: 'María José Gómez', dueCents: 1200, business: 'Moda Valentina', portalUrl: 'https://apartar.app/mi-cuenta/acceso' };

it('el recordatorio de una cuota de hoy saluda por el primer nombre y lleva el enlace al portal', () => {
    expect(reminderText({ ...base, daysLate: 0 })).toBe(
        'Hola, María. Te escribo de Moda Valentina: hoy vence tu cuota de $12,00. Puedes ver tu plan y subir el comprobante aquí: https://apartar.app/mi-cuenta/acceso',
    );
});

it('si está atrasada, dice lo pendiente sin la palabra «vence»', () => {
    expect(reminderText({ ...base, daysLate: 3 })).toContain('tienes $12,00 pendientes de tu apartado');
});

it('abre WhatsApp con el mensaje escrito; sin un celular venezolano válido no hay enlace', () => {
    const url = reminderUrl({ ...base, daysLate: 0, phone: '0414-1234567' });

    expect(url?.startsWith('https://wa.me/584141234567?text=Hola%2C%20Mar%C3%ADa.')).toBe(true);
    expect(reminderUrl({ ...base, daysLate: 0, phone: null })).toBeNull();
    expect(reminderUrl({ ...base, daysLate: 0, phone: '0212-5551234' })).toBeNull();
});

it('los céntimos se escriben al estilo venezolano y los bolívares con la tasa que se muestra', () => {
    expect(formatUsdCents(5_525)).toBe('$55,25');
    expect(formatBsCents(1_200, 893.2227)).toBe('Bs. 10.718,64');
});
