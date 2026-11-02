import { formatDate, formatDateTime, formatMoment, formatPeriod, formatRelative, formatTime } from '@/lib/dates';
import { describe, expect, it } from 'vitest';
import cases from './dates.cases.json';

const plain = (text: string) => text.replace(/\u00a0/g, ' ');
const ZONE = cases.timeZone;

describe('fechas con un solo formato', () => {
    it.each(cases.moments)('enseña $value igual que el servidor', (moment) => {
        expect(formatDate(moment.value, 'short', ZONE)).toBe(moment.short);
        expect(formatDate(moment.value, 'long', ZONE)).toBe(moment.long);
        expect(formatDate(moment.value, 'numeric', ZONE)).toBe(moment.numeric);
        expect(formatDate(moment.value, 'dayMonth', ZONE)).toBe(moment.dayMonth);
        expect(plain(formatTime(moment.value, ZONE))).toBe(moment.time);
        expect(plain(formatDateTime(moment.value, ZONE))).toBe(moment.dateTime);
    });

    it('lee un día sin hora como día del calendario, sin correrlo por la zona', () => {
        expect(formatDate('2026-10-12')).toBe('12 oct 2026');
        expect(formatDate('2026-10-12', 'short', 'Pacific/Kiritimati')).toBe('12 oct 2026');
    });

    it('no parte la hora entre líneas', () => {
        expect(formatTime('2026-10-12T01:45:00Z', ZONE)).toBe('9:45\u00a0p.\u00a0m.');
    });

    it('acorta el periodo sin repetir el año', () => {
        expect(formatPeriod('2026-10-12', '2026-11-11')).toBe('12 oct – 11 nov 2026');
        expect(formatPeriod('2026-12-15', '2027-01-14')).toBe('15 dic 2026 – 14 ene 2027');
    });

    it('devuelve el texto tal cual si no es una fecha', () => {
        expect(formatDate('pronto')).toBe('pronto');
    });

    it('dice Hoy, Ayer y Mañana con la hora y si no, el día', () => {
        const now = new Date('2026-10-12T15:00:00Z');

        expect(plain(formatRelative('2026-10-12T01:45:00Z', { now, timeZone: ZONE }))).toBe('Ayer 9:45 p. m.');
        expect(plain(formatRelative('2026-10-12T13:10:00Z', { now, timeZone: ZONE }))).toBe('Hoy 9:10 a. m.');
        expect(plain(formatRelative('2026-10-13T14:00:00Z', { now, timeZone: ZONE }))).toBe('Mañana 10:00 a. m.');
        expect(formatRelative('2026-10-02T14:00:00Z', { now, timeZone: ZONE })).toBe('2 oct');
        expect(formatRelative('2025-10-02T14:00:00Z', { now, timeZone: ZONE })).toBe('2 oct 2025');
    });

    it('dentro de una frase dice hoy, ayer y mañana en minúscula', () => {
        const now = new Date('2026-10-12T15:00:00Z');

        expect(plain(formatRelative('2026-10-13T14:00:00Z', { now, timeZone: ZONE, inSentence: true }))).toBe('mañana 10:00 a. m.');
    });

    it('en las listas muestra solo la hora si es de hoy', () => {
        const now = new Date('2026-10-12T15:00:00Z');

        expect(plain(formatMoment('2026-10-12T13:10:00Z', { now, timeZone: ZONE }))).toBe('9:10 a. m.');
        expect(formatMoment('2026-10-11T13:10:00Z', { now, timeZone: ZONE })).toBe('11 oct');
        expect(formatMoment('2025-12-11T13:10:00Z', { now, timeZone: ZONE })).toBe('11 dic 2025');
    });
});
