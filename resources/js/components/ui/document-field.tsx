import { cn } from '@/lib/utils';
import { splitDocument, useCatalogs } from '@/lib/venezuela-catalogs';
import { cilChevronBottom } from '@coreui/icons';
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';
import { type ReactNode, useId, useState } from 'react';
import { Icon } from './icon';

interface DocumentFieldProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    name?: string;
    error?: string;
    hint?: ReactNode;
    placeholder?: string;
    required?: boolean;
    autoFocus?: boolean;
}

export function DocumentField({ label, value, onChange, name, error, hint, placeholder = '12345678', required, autoFocus }: DocumentFieldProps) {
    const id = useId();
    const prefixes = useCatalogs().documentPrefixes ?? [];
    const initial = splitDocument(prefixes, value);
    const [prefix, setPrefix] = useState(initial.prefix);
    const [digits, setDigits] = useState(initial.digits);
    const selected = prefixes.find((option) => option.code === prefix) ?? null;
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ');
    const combined = digits === '' ? '' : `${prefix}${digits}`;

    const emit = (nextPrefix: string, nextDigits: string) => onChange(nextDigits === '' ? '' : `${nextPrefix}${nextDigits}`);

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                {label}
            </label>
            {name ? <input type="hidden" name={name} value={combined} /> : null}
            <div className="flex gap-2">
                <Listbox
                    value={prefix}
                    onChange={(picked: string) => {
                        setPrefix(picked);
                        emit(picked, digits);
                    }}
                >
                    <ListboxButton
                        aria-label={selected ? `Tipo de documento ${selected.code} (${selected.name})` : 'Tipo de documento'}
                        className={cn('input w-[76px] shrink-0 cursor-pointer gap-2', error && 'input-error')}
                    >
                        <span className="grow text-left font-semibold">{selected?.code ?? prefix}</span>
                        <Icon icon={cilChevronBottom} size={14} className="text-ink-3" />
                    </ListboxButton>
                    <ListboxOptions
                        anchor={{ to: 'bottom start', gap: 6 }}
                        className="bg-base-100 border-line shadow-pop z-50 w-52 rounded-xl border p-1"
                    >
                        {prefixes.map((option) => (
                            <ListboxOption
                                key={option.code}
                                value={option.code}
                                className="data-focus:bg-surface-2 flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-sm data-selected:font-bold"
                            >
                                <span className="bg-surface-2 inline-flex size-7 items-center justify-center rounded-md font-bold">
                                    {option.code}
                                </span>
                                <span>{option.name}</span>
                            </ListboxOption>
                        ))}
                    </ListboxOptions>
                </Listbox>
                <input
                    id={id}
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder={placeholder}
                    maxLength={10}
                    required={required}
                    autoFocus={autoFocus}
                    value={digits}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy === '' ? undefined : describedBy}
                    className={cn('input min-w-0 grow tabular-nums', error && 'input-error')}
                    onChange={(event) => {
                        const next = event.target.value.replace(/\D/g, '').slice(0, 10);
                        setDigits(next);
                        emit(prefix, next);
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
