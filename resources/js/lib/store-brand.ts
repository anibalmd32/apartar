import { type CSSProperties } from 'react';

type Rgb = [number, number, number];

const LIGHT_GROUND: Rgb = [244, 245, 242];
const DARK_GROUND: Rgb = [12, 14, 17];
const LIGHT_INK: Rgb = [21, 23, 27];
const DARK_INK: Rgb = [242, 243, 239];
const TEXT_CONTRAST = 4.5;

function parse(hex: string | null): Rgb | null {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex?.trim() ?? '');

    if (match === null) {
        return null;
    }

    const value = parseInt(match[1]!, 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

function toHex([r, g, b]: Rgb): string {
    return `#${[r, g, b].map((channel) => Math.round(channel).toString(16).padStart(2, '0')).join('')}`;
}

function luminance(rgb: Rgb): number {
    const [r, g, b] = rgb.map((channel) => {
        const value = channel / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    }) as Rgb;

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

export function contrast(a: Rgb, b: Rgb): number {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number];

    return (high + 0.05) / (low + 0.05);
}

function mix(color: Rgb, toward: Rgb, amount: number): Rgb {
    return color.map((channel, index) => channel + (toward[index]! - channel) * amount) as Rgb;
}

function readableOn(color: Rgb, ground: Rgb, toward: Rgb): Rgb {
    for (let step = 0; step <= 20; step++) {
        const candidate = mix(color, toward, step / 20);

        if (contrast(candidate, ground) >= TEXT_CONTRAST) {
            return candidate;
        }
    }

    return toward;
}

function contentFor(fill: Rgb): Rgb {
    return contrast(fill, [255, 255, 255]) >= contrast(fill, DARK_GROUND) ? [255, 255, 255] : DARK_GROUND;
}

export interface StorePalette {
    light: string;
    onLight: string;
    dark: string;
    onDark: string;
}

export function storePalette(brandColor: string | null): StorePalette {
    const brand = parse(brandColor);
    const light = brand === null ? LIGHT_INK : readableOn(brand, LIGHT_GROUND, [0, 0, 0]);
    const dark = brand === null ? DARK_INK : readableOn(brand, DARK_GROUND, [255, 255, 255]);

    return {
        light: toHex(light),
        onLight: toHex(contentFor(light)),
        dark: toHex(dark),
        onDark: toHex(contentFor(dark)),
    };
}

export function storeStyle(brandColor: string | null): CSSProperties {
    const palette = storePalette(brandColor);

    return {
        '--store-accent': palette.light,
        '--store-on-accent': palette.onLight,
        '--store-accent-dark': palette.dark,
        '--store-on-accent-dark': palette.onDark,
    } as CSSProperties;
}
