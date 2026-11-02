import { NumberField } from '@/components/ui/number-field';
import { SelectField } from '@/components/ui/select-field';
import { useState } from 'react';

const PRESETS = [7, 15, 30] as const;
const CUSTOM = 'custom';

interface FrequencyFieldProps {
    value: number | null;
    onChange: (days: number | null) => void;
    error?: string;
}

export function FrequencyField({ value, onChange, error }: FrequencyFieldProps) {
    const isPreset = value !== null && (PRESETS as readonly number[]).includes(value);
    const [custom, setCustom] = useState(!isPreset);
    const showDays = custom || !isPreset;

    return (
        <div className="flex flex-col">
            <SelectField
                label="Frecuencia"
                name="frequency_preset"
                options={[...PRESETS.map((days) => ({ value: String(days), label: `Cada ${days} días` })), { value: CUSTOM, label: 'Personalizada' }]}
                value={showDays ? CUSTOM : String(value)}
                onChange={(event) => {
                    const picked = event.target.value;
                    setCustom(picked === CUSTOM);

                    if (picked !== CUSTOM) {
                        onChange(Number(picked));
                    }
                }}
                error={showDays ? undefined : error}
            />

            {showDays ? (
                <NumberField
                    label="Días entre cuotas"
                    name="frequency_days"
                    min={1}
                    max={90}
                    value={value}
                    onChange={onChange}
                    error={error}
                    required
                />
            ) : null}
        </div>
    );
}
