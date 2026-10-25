import { Icon } from '@/components/ui/icon';
import { TextField } from '@/components/ui/text-field';
import { FILTER_DEBOUNCE_MS } from '@/features/data-table/hooks/useDebouncedFilter';
import { getJson } from '@/lib/json';
import { cilBellExclamation, cilX } from '@coreui/icons';
import { useEffect, useState } from 'react';

export interface RecipientOption {
    readonly id: number;
    readonly name: string;
    readonly detail: string | null;
    readonly reachable: boolean;
}

export function RecipientPicker({
    searchUrl,
    selected,
    onChange,
}: {
    searchUrl: string;
    selected: readonly RecipientOption[];
    onChange: (selected: RecipientOption[]) => void;
}) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<RecipientOption[]>([]);

    useEffect(() => {
        if (term.trim() === '') {
            return;
        }

        const timer = window.setTimeout(() => {
            void getJson<{ customers: RecipientOption[] }>(`${searchUrl}?${new URLSearchParams({ q: term }).toString()}`)
                .then((body) => setResults(body.customers))
                .catch(() => setResults([]));
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [term, searchUrl]);

    const chosen = new Set(selected.map((option) => option.id));
    const visible = term.trim() === '' ? [] : results.filter((option) => !chosen.has(option.id));

    return (
        <div className="flex flex-col gap-2">
            {selected.length > 0 ? (
                <ul aria-label="Elegidos" className="flex flex-wrap gap-1.5">
                    {selected.map((option) => (
                        <li
                            key={option.id}
                            className="bg-primary-soft text-primary-ink flex items-center gap-1 rounded-full py-1 pr-1 pl-3 text-xs font-semibold"
                        >
                            {option.name}
                            {!option.reachable ? <Icon icon={cilBellExclamation} size={12} label="Sin avisos activados" /> : null}
                            <button
                                type="button"
                                className="hover:bg-base-100 cursor-pointer rounded-full p-0.5"
                                aria-label={`Quitar a ${option.name}`}
                                onClick={() => onChange(selected.filter((other) => other.id !== option.id))}
                            >
                                <Icon icon={cilX} size={12} />
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}

            <TextField label="Buscar clientes" value={term} onChange={(event) => setTerm(event.target.value)} hint="Nombre, cédula o teléfono." />

            {visible.length > 0 ? (
                <ul aria-label="Resultados" className="border-line divide-line divide-y rounded-xl border">
                    {visible.map((option) => (
                        <li key={option.id}>
                            <button
                                type="button"
                                className="hover:bg-surface-2 flex w-full cursor-pointer items-center justify-between gap-3 px-3 py-2 text-left"
                                onClick={() => {
                                    onChange([...selected, option]);
                                    setTerm('');
                                }}
                            >
                                <span className="flex min-w-0 flex-col">
                                    <span className="text-sm font-semibold">{option.name}</span>
                                    {option.detail !== null ? <span className="text-ink-3 text-xs">{option.detail}</span> : null}
                                </span>
                                <span className={option.reachable ? 'text-success text-xs' : 'text-ink-3 text-xs'}>
                                    {option.reachable ? 'Avisos activados' : 'Sin avisos'}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            {term.trim() !== '' && results.length === 0 ? <p className="text-ink-3 text-xs">Ninguna ficha coincide.</p> : null}
        </div>
    );
}
