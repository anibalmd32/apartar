import { SectionCard } from '@/components/ui/section-card';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type BackofficeLayawayCreateProps, type DownPaymentWindowChoice } from '@/types/layaway-backoffice';

const WINDOW_OPTIONS: readonly { value: DownPaymentWindowChoice; label: string }[] = [
    { value: 'default', label: 'El plazo del negocio' },
    { value: 'hours', label: 'Unas horas' },
    { value: 'days', label: 'Unos días' },
    { value: 'none', label: 'Sin vencimiento' },
];

export function windowSummary(choice: DownPaymentWindowChoice, value: string, defaultHours: number): string {
    const amount = Number(value);

    switch (choice) {
        case 'none':
            return 'La inicial no vence: el apartado espera hasta que la registres.';
        case 'hours':
            return amount > 0 ? `Vence ${amount === 1 ? 'en 1 hora' : `en ${amount} horas`} si no se paga la inicial.` : 'Escribe cuántas horas.';
        case 'days':
            return amount > 0 ? `Vence ${amount === 1 ? 'en 1 día' : `en ${amount} días`} si no se paga la inicial.` : 'Escribe cuántos días.';
        default:
            return `Vence en ${defaultHours} horas si no se paga la inicial, como en tu catálogo.`;
    }
}

interface DownPaymentWindowFieldsProps {
    choice: DownPaymentWindowChoice;
    value: string;
    onChange: (choice: DownPaymentWindowChoice, value: string) => void;
    limits: BackofficeLayawayCreateProps['window'];
    errors: { window?: string; window_value?: string };
}

export function DownPaymentWindowFields({ choice, value, onChange, limits, errors }: DownPaymentWindowFieldsProps) {
    const counted = choice === 'hours' || choice === 'days';

    return (
        <SectionCard title="Plazo para la inicial" subtitle="Mientras no se pague la inicial, el apartado espera.">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <SelectField
                    label="Vence"
                    name="window"
                    options={WINDOW_OPTIONS}
                    value={choice}
                    onChange={(event) => onChange(event.target.value as DownPaymentWindowChoice, '')}
                    error={errors.window}
                />
                {counted ? (
                    <TextField
                        label={choice === 'hours' ? 'Horas' : 'Días'}
                        name="window_value"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={choice === 'hours' ? limits.maxHours : limits.maxDays}
                        value={value}
                        onChange={(event) => onChange(choice, event.target.value)}
                        error={errors.window_value}
                    />
                ) : null}
            </div>
            <p className="text-ink-3 text-xs">{windowSummary(choice, value, limits.defaultHours)}</p>
        </SectionCard>
    );
}
