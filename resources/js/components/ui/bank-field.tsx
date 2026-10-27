import { cn } from '@/lib/utils';
import { findBank, normalizeName, useCatalogs } from '@/lib/venezuela-catalogs';
import { cilChevronBottom } from '@coreui/icons';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import { type ReactNode, useId, useState } from 'react';
import { BankMark } from './bank-mark';
import { Icon } from './icon';

interface BankFieldProps {
    label: string;
    value: string;
    onChange: (value: string) => void;
    name?: string;
    error?: string;
    hint?: ReactNode;
    required?: boolean;
}

export function BankField({ label, value, onChange, name, error, hint, required }: BankFieldProps) {
    const id = useId();
    const { banks } = useCatalogs();
    const [query, setQuery] = useState('');
    const typed = query.trim();
    const matches =
        typed === '' ? banks : banks.filter((bank) => normalizeName(bank.name).includes(normalizeName(typed)) || bank.code.startsWith(typed));
    const offersOther = typed !== '' && matches.length === 0;
    const current = findBank(banks, value);
    const describedBy = [error ? `${id}-error` : null, hint ? `${id}-hint` : null].filter(Boolean).join(' ');

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                {label}
            </label>
            <Combobox value={value} onChange={(picked: string | null) => onChange(picked ?? '')} onClose={() => setQuery('')} immediate>
                <div className={cn('input w-full gap-2', error && 'input-error')}>
                    {value !== '' && query === '' ? <BankMark bank={value} logoUrl={current?.logoUrl} className="shrink-0" /> : null}
                    <ComboboxInput
                        id={id}
                        name={name}
                        className="min-w-0 grow"
                        autoComplete="off"
                        placeholder="Busca tu banco"
                        required={required}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={describedBy === '' ? undefined : describedBy}
                        displayValue={(current: string) => current}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <ComboboxButton aria-label="Ver bancos" className="text-ink-3 shrink-0 cursor-pointer">
                        <Icon icon={cilChevronBottom} size={14} />
                    </ComboboxButton>
                </div>
                <ComboboxOptions
                    anchor={{ to: 'bottom start', gap: 6 }}
                    className="bg-base-100 border-line shadow-pop z-50 max-h-72 w-(--input-width) min-w-64 overflow-y-auto rounded-xl border p-1 empty:invisible"
                >
                    {matches.map((bank) => (
                        <ComboboxOption
                            key={bank.code}
                            value={bank.name}
                            className="data-focus:bg-surface-2 flex cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-sm data-selected:font-bold"
                        >
                            <span className="flex w-24 shrink-0 items-center">
                                <BankMark bank={bank.name} logoUrl={bank.logoUrl} />
                            </span>
                            <span className="min-w-0 grow truncate">{bank.name}</span>
                            <span className="text-ink-3 shrink-0 text-xs tabular-nums">{bank.code}</span>
                        </ComboboxOption>
                    ))}
                    {offersOther ? (
                        <ComboboxOption
                            value={typed}
                            className="data-focus:bg-surface-2 text-primary-ink cursor-pointer rounded-lg px-2.5 py-2 text-sm"
                        >
                            Otro banco: «{typed}»
                        </ComboboxOption>
                    ) : null}
                </ComboboxOptions>
            </Combobox>
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
