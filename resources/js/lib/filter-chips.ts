import { formatIsoDate } from '@/lib/iso-date';

interface Option {
    readonly value: string;
    readonly label: string;
}

export interface FilterChip<K extends string> {
    key: K;
    label: string;
}

function blank(value: string | null | undefined): value is null | undefined | '' {
    return value === null || value === undefined || value === '';
}

export function optionChip<K extends string>(
    key: K,
    prefix: string,
    value: string | null | undefined,
    options: readonly Option[],
): FilterChip<K> | null {
    if (blank(value)) {
        return null;
    }

    return { key, label: `${prefix}: ${options.find((option) => option.value === value)?.label ?? value}` };
}

export function dateChip<K extends string>(key: K, prefix: string, value: string | null | undefined): FilterChip<K> | null {
    return blank(value) ? null : { key, label: `${prefix} ${formatIsoDate(value) ?? value}` };
}

export function flagChip<K extends string>(key: K, label: string, on: boolean): FilterChip<K> | null {
    return on ? { key, label } : null;
}

export function present<K extends string>(chips: readonly (FilterChip<K> | null)[]): FilterChip<K>[] {
    return chips.filter((chip): chip is FilterChip<K> => chip !== null);
}
