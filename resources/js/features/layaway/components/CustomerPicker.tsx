import { Icon } from '@/components/ui/icon';
import { TextField } from '@/components/ui/text-field';
import { FILTER_DEBOUNCE_MS } from '@/features/data-table/hooks/useDebouncedFilter';
import { getJson } from '@/lib/json';
import { type LayawayCustomerOption } from '@/types/layaway-backoffice';
import { cilX } from '@coreui/icons';
import { useEffect, useState } from 'react';

interface CustomerPickerProps {
    searchUrl: string;
    selected: LayawayCustomerOption | null;
    onChange: (selected: LayawayCustomerOption | null) => void;
    error?: string;
}

export function CustomerPicker({ searchUrl, selected, onChange, error }: CustomerPickerProps) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<LayawayCustomerOption[]>([]);

    useEffect(() => {
        if (term.trim() === '') {
            return;
        }

        const timer = window.setTimeout(() => {
            void getJson<{ customers: LayawayCustomerOption[] }>(`${searchUrl}?${new URLSearchParams({ q: term }).toString()}`)
                .then((body) => setResults(body.customers))
                .catch(() => setResults([]));
        }, FILTER_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [term, searchUrl]);

    if (selected !== null) {
        return (
            <div className="flex flex-col gap-1">
                <div className="bg-primary-soft text-primary-ink flex items-center justify-between gap-3 rounded-xl px-3 py-2">
                    <span className="flex min-w-0 flex-col">
                        <span className="text-sm font-semibold">{selected.name}</span>
                        {selected.detail !== null ? <span className="text-xs opacity-80">{selected.detail}</span> : null}
                    </span>
                    <button
                        type="button"
                        className="hover:bg-base-100 cursor-pointer rounded-full p-1"
                        aria-label={`Cambiar de cliente: quitar a ${selected.name}`}
                        onClick={() => onChange(null)}
                    >
                        <Icon icon={cilX} size={14} />
                    </button>
                </div>
                {error !== undefined ? <p className="text-error text-xs">{error}</p> : null}
            </div>
        );
    }

    const visible = term.trim() === '' ? [] : results;

    return (
        <div className="flex flex-col gap-2">
            <TextField
                label="Buscar cliente"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                hint="Nombre, cédula o teléfono."
                error={error}
            />

            {visible.length > 0 ? (
                <ul aria-label="Clientes encontrados" className="border-line divide-line divide-y rounded-xl border">
                    {visible.map((option) => (
                        <li key={option.id}>
                            <button
                                type="button"
                                className="hover:bg-surface-2 flex w-full cursor-pointer flex-col px-3 py-2 text-left"
                                onClick={() => {
                                    onChange(option);
                                    setTerm('');
                                }}
                            >
                                <span className="text-sm font-semibold">{option.name}</span>
                                {option.detail !== null ? <span className="text-ink-3 text-xs">{option.detail}</span> : null}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            {term.trim() !== '' && results.length === 0 ? (
                <p className="text-ink-3 text-xs">Ninguna ficha coincide. Puedes darlo de alta como cliente nuevo.</p>
            ) : null}
        </div>
    );
}
