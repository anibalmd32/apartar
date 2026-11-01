import { TextField } from '@/components/ui/text-field';
import { type RateOfDayState } from '@/features/receipts/hooks/useRateOfDay';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { dayLabel } from '@/features/receipts/lib/payment-rate';
import { RATE_DECIMALS, typedAmount } from '@/lib/amount-typing';

interface RateFieldProps {
    value: string;
    onChange: (rate: string) => void;
    manual: boolean;
    onManualChange: (manual: boolean) => void;
    day: string;
    today: string;
    ofDay: RateOfDayState;
    error?: string;
}

const linkClass = 'link link-primary link-hover ml-1 font-semibold';

export function RateField({ value, onChange, manual, onManualChange, day, today, ofDay, error }: RateFieldProps) {
    const when = day === '' || day >= today ? 'vigente' : `del ${dayLabel(day)}`;
    const missing = ofDay.known && ofDay.rate === null;
    const writing = manual || missing;

    const takeOver = () => {
        onManualChange(true);
        onChange(ofDay.rate === null ? '' : formatRate(ofDay.rate.rate));
    };

    const giveBack = () => {
        onManualChange(false);
        onChange('');
    };

    const hint = missing ? (
        `No hay tasa conocida ${when === 'vigente' ? 'vigente' : `para el ${dayLabel(day)}`}: escribe la que usaste.`
    ) : manual ? (
        <>
            Queda registrada como tasa manual.
            <button type="button" className={linkClass} onClick={giveBack}>
                Usar la {when}
            </button>
        </>
    ) : ofDay.rate === null ? (
        `Buscando la tasa ${when}…`
    ) : (
        <>
            Tasa {ofDay.rate.sourceLabel} {when}, la que se registra.
            <button type="button" className={linkClass} onClick={takeOver}>
                Escribir otra
            </button>
        </>
    );

    return (
        <TextField
            label="Tasa (Bs por dólar)"
            name="rate"
            inputMode="decimal"
            value={writing ? value : ofDay.rate === null ? '' : formatRate(ofDay.rate.rate)}
            onChange={(event) => onChange(typedAmount(event, RATE_DECIMALS))}
            disabled={!writing}
            error={error}
            hint={hint}
        />
    );
}
