import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { SettingScopeBadge } from '@/features/tenancy/components/SettingScopeBadge';
import { type OrganizationSettings, type SettingDefinition, type SettingValue } from '@/types/backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * Los ajustes de operación (`REQ-ORG-006`, [02 §9.1]).
 *
 * Cada campo lleva **su alcance al lado** (RB-4): guardar no es aplicar, y
 * quien cambia un plazo tiene que saber a qué afecta antes de pulsar el botón.
 * El nombre del campo y la frase del alcance salen del servidor, no de aquí:
 * repetirlos haría que la pantalla pudiera decir una cosa distinta de la que
 * hace el cálculo.
 *
 * **La fuente de la tasa y la tasa manual ya no están aquí**: se fueron a
 * `ExchangeRateForm` con `T-021`. No es una separación estética —son un par
 * que solo tiene sentido guardado junto (`REQ-FX-003` SP-1) y su pantalla
 * necesita las tasas vigentes de cada fuente para poder comparar (RB-6)—, y
 * dejar aquí una segunda casilla que escribiera las mismas dos claves habría
 * sido el camino por el que se cambia la fuente sin pasar por su validación
 * cruzada.
 *
 * `reminder_offsets_days` se escribe como lista separada por comas y viaja
 * como array. Es el único campo que necesita traducción, y va en su propia
 * función para que se vea: «-3, 0, 1» escrito por una persona no es `[-3, 0,
 * 1]`, y el sitio donde esa diferencia se pierde siempre es el que no tiene
 * nombre.
 */

const DISPLAY_OPTIONS = [
    { value: 'usd_primary', label: 'Dólares primero' },
    { value: 'ves_primary', label: 'Bolívares primero' },
];

const NUMERIC_KEYS = [
    'grace_period_days',
    'down_payment_window_hours',
    'settlement_tolerance_cents',
    'default_frequency_days',
    'default_installments_count',
    'overdue_alert_days',
];

interface SettingsForm {
    [key: string]: OrganizationSettings;
    settings: OrganizationSettings;
}

interface OperationSettingsFormProps {
    settings: OrganizationSettings;
    definitions: readonly SettingDefinition[];
}

/** Convierte lo escrito —«-3, 0, 1»— en la lista de enteros que espera el servidor. */
function parseOffsets(raw: string): number[] {
    return raw
        .split(',')
        .map((piece) => piece.trim())
        .filter((piece) => piece !== '')
        .map(Number)
        .filter((value) => Number.isInteger(value));
}

export function OperationSettingsForm({ settings, definitions }: OperationSettingsFormProps) {
    const offsets = Array.isArray(settings.reminder_offsets_days) ? (settings.reminder_offsets_days as number[]) : [];

    const { data, setData, put, processing, errors } = useForm<SettingsForm>({
        settings: { ...settings, reminder_offsets_days: offsets },
    });

    const value = (key: string): string => {
        const current = data.settings[key];

        return current === null || current === undefined ? '' : String(current);
    };

    const change = (key: string, next: SettingValue) => setData('settings', { ...data.settings, [key]: next });

    const definitionOf = (key: string) => definitions.find((definition) => definition.key === key);

    const labelOf = (key: string) => definitionOf(key)?.label ?? key;

    const scopeOf = (key: string) => {
        const definition = definitionOf(key);

        return definition ? <SettingScopeBadge scope={definition.scope} note={definition.scopeNote} /> : undefined;
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        put('/backoffice/ajustes', { preserveScroll: true });
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={submit} noValidate>
            <div className="grid gap-2 sm:grid-cols-2">
                <SelectField
                    label={labelOf('currency_display_default')}
                    options={DISPLAY_OPTIONS}
                    value={value('currency_display_default')}
                    onChange={(event) => change('currency_display_default', event.target.value)}
                    error={errors['settings.currency_display_default']}
                    hint={scopeOf('currency_display_default')}
                />

                {NUMERIC_KEYS.map((key) => (
                    <TextField
                        key={key}
                        label={labelOf(key)}
                        type="number"
                        value={value(key)}
                        onChange={(event) => change(key, event.target.value === '' ? '' : Number(event.target.value))}
                        error={errors[`settings.${key}`]}
                        hint={scopeOf(key)}
                    />
                ))}

                <TextField
                    label={labelOf('reminder_offsets_days')}
                    defaultValue={offsets.join(', ')}
                    onChange={(event) => change('reminder_offsets_days', parseOffsets(event.target.value))}
                    error={errors['settings.reminder_offsets_days']}
                    hint={scopeOf('reminder_offsets_days')}
                />
            </div>

            <Button type="submit" loading={processing} className="self-start">
                Guardar ajustes
            </Button>
        </form>
    );
}
