import { Button } from '@/components/ui/button';
import { FormActionBar } from '@/components/ui/form-action-bar';
import { useEffect, useMemo, useState } from 'react';

export interface SettingsFormState {
    dirty: boolean;
    processing: boolean;
}

export interface SettingsSectionLink {
    formId?: string;
    onStateChange?: (state: SettingsFormState) => void;
    savesFromBar?: boolean;
}

export const SETTINGS_FORMS = [
    { id: 'identidad', formId: 'ajustes-identidad', label: 'identidad' },
    { id: 'tasa', formId: 'ajustes-tasa', label: 'tasa de cambio' },
    { id: 'operacion', formId: 'ajustes-operacion', label: 'operación' },
] as const;

export type SettingsFormId = (typeof SETTINGS_FORMS)[number]['id'];

export type SettingsFormStates = Partial<Record<SettingsFormId, SettingsFormState>>;

export function useReportFormState(onStateChange: SettingsSectionLink['onStateChange'], dirty: boolean, processing: boolean) {
    useEffect(() => {
        onStateChange?.({ dirty, processing });
    }, [onStateChange, dirty, processing]);
}

export function barSection(states: SettingsFormStates): SettingsFormId | null {
    return SETTINGS_FORMS.find((form) => states[form.id]?.dirty)?.id ?? null;
}

type Reporters = Record<SettingsFormId, (state: SettingsFormState) => void>;

export function useSettingsForms() {
    const [states, setStates] = useState<SettingsFormStates>({});

    const reporters = useMemo(() => {
        const entries = SETTINGS_FORMS.map((form) => [
            form.id,
            (state: SettingsFormState) =>
                setStates((previous) => {
                    const known = previous[form.id];

                    return known?.dirty === state.dirty && known.processing === state.processing ? previous : { ...previous, [form.id]: state };
                }),
        ]);

        return Object.fromEntries(entries) as Reporters;
    }, []);

    const owner = barSection(states);

    const linkOf = (id: SettingsFormId): SettingsSectionLink => ({
        formId: SETTINGS_FORMS.find((form) => form.id === id)?.formId,
        onStateChange: reporters[id],
        savesFromBar: owner === id,
    });

    return {
        states,
        linkOf,
        dirty: owner !== null,
        processing: Object.values(states).some((state) => state.processing),
    };
}

export function SettingsSaveBar({ states }: { states: SettingsFormStates }) {
    const dirty = SETTINGS_FORMS.filter((form) => states[form.id]?.dirty);

    const [current, ...others] = dirty;

    if (current === undefined) {
        return null;
    }

    return (
        <FormActionBar sticky className="max-lg:-mx-4 max-lg:px-4 lg:hidden">
            {others.length > 0 ? (
                <p className="text-ink-2 text-sm">También tienes cambios sin guardar en {others.map((form) => form.label).join(' y ')}.</p>
            ) : null}
            <Button type="submit" form={current.formId} loading={states[current.id]?.processing}>
                Guardar {current.label}
            </Button>
        </FormActionBar>
    );
}
