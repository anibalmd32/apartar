import { cn } from '@/lib/utils';
import { splitMobile, useCatalogs } from '@/lib/venezuela-catalogs';
import { cilChevronBottom } from '@coreui/icons';
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';
import { type ReactNode, useId, useState } from 'react';
import { BankMark } from './bank-mark';
import { Icon } from './icon';

interface PhoneFieldProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    name?: string;
    error?: string;
    hint?: ReactNode;
    required?: boolean;
}

export function PhoneField({ label, value, onChange, name, error, hint, required }: PhoneFieldProps) {
    const id = useId();
    const { mobilePrefixes } = useCatalogs();
    const initial = splitMobile(mobilePrefixes, value);
    const [prefix, setPrefix] = useState(initial.prefix);
    const [number, setNumber] = useState(initial.number);
    const selected = mobilePrefixes.find((option) => option.prefix === prefix) ?? null;
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ');

    const emit = (nextPrefix: string, nextNumber: string) => onChange(nextPrefix === '' && nextNumber === '' ? '' : `${nextPrefix}${nextNumber}`);

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                {label}
            </label>
            {name ? <input type="hidden" name={name} value={prefix === '' && number === '' ? '' : `${prefix}${number}`} /> : null}
            <div className="flex gap-2">
                <Listbox
                    value={prefix}
                    onChange={(picked: string) => {
                        setPrefix(picked);
                        emit(picked, number);
                    }}
                >
                    <ListboxButton
                        aria-label={selected ? `Prefijo ${selected.prefix} (${selected.carrier})` : 'Prefijo'}
                        className={cn('input w-[128px] shrink-0 cursor-pointer gap-2', error && 'input-error')}
                    >
                        {selected ? <BankMark bank={selected.carrier} logoUrl={selected.logoUrl} /> : null}
                        <span className={cn('grow text-left tabular-nums', selected ? 'text-base-content' : 'text-ink-3')}>
                            {selected?.prefix ?? '04··'}
                        </span>
                        <Icon icon={cilChevronBottom} size={14} className="text-ink-3" />
                    </ListboxButton>
                    <ListboxOptions
                        anchor={{ to: 'bottom start', gap: 6 }}
                        className="bg-base-100 border-line shadow-pop z-50 w-56 rounded-xl border p-1"
                    >
                        {mobilePrefixes.map((option) => (
                            <ListboxOption
                                key={option.prefix}
                                value={option.prefix}
                                className="data-focus:bg-surface-2 flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-sm data-selected:font-bold"
                            >
                                <BankMark bank={option.carrier} logoUrl={option.logoUrl} />
                                <span className="tabular-nums">{option.prefix}</span>
                                <span className="text-ink-3 ml-auto text-xs">{option.carrier}</span>
                            </ListboxOption>
                        ))}
                    </ListboxOptions>
                </Listbox>
                <input
                    id={id}
                    type="tel"
                    inputMode="numeric"
                    autoComplete="tel-local"
                    placeholder="1234567"
                    maxLength={7}
                    required={required}
                    value={number}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy === '' ? undefined : describedBy}
                    className={cn('input min-w-0 grow tabular-nums', error && 'input-error')}
                    onChange={(event) => {
                        const digits = event.target.value.replace(/\D/g, '').slice(0, 7);
                        setNumber(digits);
                        emit(prefix, digits);
                    }}
                />
            </div>
            {hint ? (
                <p id={`${id}-hint`} className="label text-xs whitespace-normal">
                    {hint}
                </p>
            ) : null}
            {error ? (
                <p id={`${id}-error`} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
