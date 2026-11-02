import { describe, expect, it } from 'vitest';

const sources = import.meta.glob(['../**/*.tsx', '!../**/*.test.tsx'], { query: '?raw', import: 'default', eager: true }) as Record<string, string>;

function offending(pattern: RegExp, allowed: (lines: string[], index: number) => boolean = () => false): string[] {
    return Object.entries(sources).flatMap(([file, source]) => {
        const lines = source.split('\n');

        return lines.flatMap((line, index) => (pattern.test(line) && !allowed(lines, index) ? [`${file}:${index + 1}`] : []));
    });
}

describe('tipografía de la app', () => {
    it('ningún rótulo usa mayúsculas con espaciado: son subtítulos en minúscula normal', () => {
        expect(Object.keys(sources).length).toBeGreaterThan(100);
        expect(offending(/text-eyebrow|(?<![\w:-])uppercase\b/)).toEqual([]);
    });

    it('los títulos usan el espaciado compartido y no un tracking negativo suelto que pega las palabras', () => {
        const wordmark = (lines: string[], index: number) => lines.slice(index, index + 3).some((line) => /(>|^\s*)apartar(<|\s*$)/.test(line));

        expect(offending(/tracking-\[-/, wordmark)).toEqual([]);
    });

    it('ningún estilo con espaciado negativo se achica por debajo de 24px en el celular', () => {
        const tight = /(?<![\w:-])(text-h1|text-figure|text-display|tracking-heading)\b/;
        const small = /(?<![\w-])(?:max-[\w]+:)?text-(?:\[(?:1\d|2[0-3])px\]|xs|sm|base|lg|xl)(?![\w-])/;

        const shrinks = (line: string) => (line.match(/"[^"]*"|'[^']*'/g) ?? []).some((classes) => tight.test(classes) && small.test(classes));

        expect(offending(/./, (lines, index) => !shrinks(lines[index] ?? ''))).toEqual([]);
    });
});
