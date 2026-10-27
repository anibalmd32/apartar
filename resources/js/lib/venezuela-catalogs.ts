import { usePage } from '@inertiajs/react';

export interface VenezuelanBank {
    readonly id: number;
    readonly code: string;
    readonly name: string;
    readonly logoUrl: string | null;
}

export interface MobilePrefix {
    readonly id: number;
    readonly prefix: string;
    readonly carrier: string;
    readonly logoUrl: string | null;
}

export interface Catalogs {
    readonly banks: readonly VenezuelanBank[];
    readonly mobilePrefixes: readonly MobilePrefix[];
}

const EMPTY: Catalogs = { banks: [], mobilePrefixes: [] };

export function useCatalogs(): Catalogs {
    return usePage<{ catalogs?: Catalogs }>().props.catalogs ?? EMPTY;
}

export function normalizeName(value: string): string {
    return value.normalize('NFD').replace(/\p{M}/gu, '').trim().toLowerCase();
}

export function findBank(banks: readonly VenezuelanBank[], nameOrCode: string | null | undefined): VenezuelanBank | null {
    if (!nameOrCode || nameOrCode.trim() === '') {
        return null;
    }

    const needle = normalizeName(nameOrCode);

    return banks.find((bank) => bank.code === nameOrCode.trim() || normalizeName(bank.name) === needle) ?? null;
}

export function splitMobile(prefixes: readonly MobilePrefix[], value: string): { prefix: string; number: string } {
    const digits = value.replace(/\D/g, '');
    const national =
        digits.startsWith('58') && digits.length === 12 ? `0${digits.slice(2)}` : digits.startsWith('0') ? digits : digits === '' ? '' : `0${digits}`;
    const prefix = prefixes.find((option) => national.startsWith(option.prefix))?.prefix ?? '';

    return { prefix, number: prefix === '' ? '' : national.slice(prefix.length, prefix.length + 7) };
}
