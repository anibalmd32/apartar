import { chartData } from '@/features/platform/mail/DailyChart';
import { DASH, fileSize, minutes, percent, statusTone } from '@/features/platform/mail/format';
import { expect, it } from 'vitest';

const day = (date: string, sent: number) => ({ date, sent, delivered: sent, opened: 0, clicked: 0, bounced: 0 });

it('formatea tasas, tamaños y tiempos en español', () => {
    expect(percent(0.6667)).toMatch(/66,7\s?%/);
    expect(percent(null)).toBe(DASH);
    expect(fileSize(2048)).toBe('2 KB');
    expect(fileSize(3_145_728)).toBe('3 MB');
    expect(fileSize(null)).toBe('');
    expect(minutes(45)).toBe('45 min');
    expect(minutes(90)).toBe('1,5 h');
    expect(minutes(null)).toBe(DASH);
});

it('da tono de error a los rebotes y de éxito a las entregas', () => {
    expect(statusTone('bounced')).toBe('error');
    expect(statusTone('delivered')).toBe('success');
    expect(statusTone('desconocido')).toBe('neutral');
});

it('agrupa por día los rangos cortos y por semana los largos', () => {
    const short = chartData([day('2026-10-01', 2), day('2026-10-02', 3)], 'sent');

    expect(short.map((bar) => bar.value)).toEqual([2, 3]);
    expect(short[0]?.label).toBe('1');
    expect(short[0]?.title).toMatch(/^1 oct.*: 2$/);

    const long = chartData(
        Array.from({ length: 60 }, (_, index) => day(new Date(Date.UTC(2026, 7, 1 + index)).toISOString().slice(0, 10), 1)),
        'sent',
    );

    expect(long).toHaveLength(9);
    expect(long[0]?.value).toBe(7);
    expect(long[8]?.value).toBe(4);
    expect(long[0]?.title).toMatch(/^Semana del 1 ago/);
});
