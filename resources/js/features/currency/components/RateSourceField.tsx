import { SelectField } from '@/components/ui/select-field';
import { bo } from '@/lib/surfaces';
import { type RateSourceChoices } from '@/types/exchange';
import { Link } from '@inertiajs/react';

interface RateSourceFieldProps {
    choices: RateSourceChoices;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint: string;
}

export function RateSourceField({ choices, value, onChange, error, hint }: RateSourceFieldProps) {
    const missingManual = value === 'manual' && !choices.hasManualRate;

    return (
        <SelectField
            label="Tasa para los bolívares"
            name="rate_source"
            options={choices.options}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            error={error}
            hint={
                missingManual ? (
                    <span className="text-warning">
                        Todavía no escribiste tu tasa propia.{' '}
                        <Link href={bo('/ajustes')} className="font-semibold underline">
                            Escríbela en Ajustes
                        </Link>{' '}
                        antes de guardar.
                    </span>
                ) : (
                    hint
                )
            }
        />
    );
}
