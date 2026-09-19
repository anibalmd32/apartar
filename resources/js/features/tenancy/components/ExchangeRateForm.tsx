import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type RateSource, type RateView } from '@/types/exchange';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Con qué tasa proyecta este negocio sus precios (`REQ-FX-003`).
 *
 * **La ingesta es de plataforma; la elección es de cada negocio** (`D-6`): dos
 * organizaciones pueden mostrar el mismo producto con tasas distintas, cada
 * una con su sello.
 *
 * Tiene formulario propio y no vive en los ajustes de operación porque la
 * fuente y la tasa manual son un par: elegir «manual» **exige** escribir la
 * cifra en la misma operación (SP-1), y el servidor responde `422` si no llega.
 * Esa regla no se puede expresar guardando las diez claves a la vez.
 *
 * Y lleva al lado **la tasa vigente de cada fuente con su antigüedad** (RB-6),
 * porque sin eso la elección sería a ciegas entre tres palabras: lo que el
 * dueño necesita saber es que hoy el BCV está a 36,50 y el paralelo a 42,20.
 */

const SOURCE_OPTIONS = [
    { value: 'bcv', label: 'BCV · tasa oficial' },
    { value: 'parallel', label: 'Paralelo' },
    { value: 'manual', label: 'Manual · la que yo fije' },
];

interface ExchangeRateFormProps {
    source: RateSource;
    manualRate: string;
    /** Las fuentes de plataforma con su tasa vigente (RB-6). */
    rateSources: readonly RateView[];
}

interface ExchangeRateFormData {
    [key: string]: string | null;
    exchange_rate_source: string;
    exchange_rate_manual: string | null;
}

/** Cómo se llama cada fuente para una persona. */
function labelOf(source: RateSource): string {
    return SOURCE_OPTIONS.find((option) => option.value === source)?.label ?? source;
}

/**
 * «hace 12 min», sobre **`publishedAt`**.
 *
 * Es la fecha en que la fuente publicó la tasa, no la hora a la que corrió
 * nuestra ingesta: es la antigüedad que le importa al negocio, y es la misma
 * corrección que `T-019` llevó al contrato de `RateView`. Un fin de semana sin
 * publicación diría «hace 5 minutos» sobre una tasa del viernes si midiéramos
 * lo otro.
 */
export function publishedAgo(publishedAt: string | null, now: Date = new Date()): string {
    if (publishedAt === null) {
        return 'sin fecha';
    }

    const minutes = Math.floor((now.getTime() - new Date(publishedAt).getTime()) / 60_000);

    if (minutes < 1) {
        return 'hace un momento';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `hace ${hours} h`;
    }

    return `hace ${Math.floor(hours / 24)} d`;
}

export function ExchangeRateForm({ source, manualRate, rateSources }: ExchangeRateFormProps) {
    const { data, setData, put, processing, errors } = useForm<ExchangeRateFormData>({
        exchange_rate_source: source,
        exchange_rate_manual: manualRate,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        put('/backoffice/ajustes/tasa', { preserveScroll: true });
    };

    return (
        <form className="flex flex-col gap-4" onSubmit={submit} noValidate>
            <ul className="menu menu-horizontal rounded-box bg-base-200 w-full flex-col gap-2 p-3 sm:flex-row">
                {rateSources.map((rate) => (
                    <li key={rate.source} className="flex-1">
                        <div className="flex flex-col items-start gap-0 hover:bg-transparent">
                            <span className="text-sm font-medium">{labelOf(rate.source)}</span>

                            {rate.rate === null ? (
                                // SP-4: se puede elegir igual. La pantalla dice
                                // que aún no hay cifra en vez de esconder la
                                // opción o bloquear el selector.
                                <span className="text-xs opacity-70">Todavía sin tasa registrada</span>
                            ) : (
                                <span className="text-xs opacity-70">
                                    Bs. {rate.rate} · {publishedAgo(rate.publishedAt)}
                                    {rate.isStale ? ' · desactualizada' : ''}
                                </span>
                            )}
                        </div>
                    </li>
                ))}
            </ul>

            <div className="grid gap-2 sm:grid-cols-2">
                <SelectField
                    label="Fuente de la tasa"
                    options={SOURCE_OPTIONS}
                    value={data.exchange_rate_source}
                    onChange={(event) => setData('exchange_rate_source', event.target.value)}
                    error={errors.exchange_rate_source}
                    hint="Con esta tasa se proyectan tus precios en bolívares."
                />

                <TextField
                    label="Tasa manual (Bs. por USD)"
                    type="number"
                    step="0.01"
                    min="0"
                    value={data.exchange_rate_manual ?? ''}
                    onChange={(event) => setData('exchange_rate_manual', event.target.value === '' ? null : event.target.value)}
                    error={errors.exchange_rate_manual}
                    hint={
                        data.exchange_rate_source === 'manual'
                            ? 'Obligatoria: es la tasa con la que vas a vender.'
                            : 'Se guarda aunque no la uses, por si vuelves a «manual».'
                    }
                />
            </div>

            <Button type="submit" loading={processing} className="self-start">
                Guardar fuente
            </Button>
        </form>
    );
}
