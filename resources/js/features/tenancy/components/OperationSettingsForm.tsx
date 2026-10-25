import { Button } from '@/components/ui/button';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { ReminderOffsetsEditor } from '@/features/tenancy/components/ReminderOffsetsEditor';
import { SettingScopeBadge } from '@/features/tenancy/components/SettingScopeBadge';
import { type OrganizationSettings, type SettingDefinition, type SettingValue } from '@/types/backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

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
            </div>

            <ReminderOffsetsEditor
                label={labelOf('reminder_offsets_days')}
                value={Array.isArray(data.settings.reminder_offsets_days) ? (data.settings.reminder_offsets_days as number[]) : []}
                onChange={(next) => change('reminder_offsets_days', next)}
                error={
                    errors['settings.reminder_offsets_days'] ??
                    Object.entries(errors).find(([key]) => key.startsWith('settings.reminder_offsets_days.'))?.[1]
                }
                hint={scopeOf('reminder_offsets_days')}
            />

            <Button type="submit" loading={processing} className="self-start">
                Guardar ajustes
            </Button>
        </form>
    );
}
