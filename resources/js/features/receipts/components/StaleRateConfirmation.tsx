import { Alert } from '@/components/ui/alert';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { type ApprovalPreviewView } from '@/types/receipt';

interface StaleRateConfirmationProps {
    staleRate: ApprovalPreviewView['staleRate'];
    confirmed: string | null;
    onChange: (token: string | null) => void;
    error: string | undefined;
}

export function StaleRateConfirmation({ staleRate, confirmed, onChange, error }: StaleRateConfirmationProps) {
    if (staleRate === null) {
        return error !== undefined ? <Alert tone="warning">{error}</Alert> : null;
    }

    return (
        <Alert tone="warning" className="flex-col items-start">
            <span>
                La tasa {staleRate.sourceLabel} se publicó hace {staleRate.days} días. El pago la conservará para siempre: si no es la del día del
                pago, escribe la tasa a mano.
            </span>
            <CheckboxField
                label={`Confirmo que convierto con la tasa de hace ${staleRate.days} días`}
                name="confirm_stale_rate"
                checked={confirmed === staleRate.token}
                onChange={(event) => onChange(event.target.checked ? staleRate.token : null)}
                error={error}
            />
        </Alert>
    );
}

interface RateDeviationConfirmationProps {
    deviation: ApprovalPreviewView['rateDeviation'];
    confirmed: string | null;
    onChange: (token: string | null) => void;
    error: string | undefined;
}

export function RateDeviationConfirmation({ deviation, confirmed, onChange, error }: RateDeviationConfirmationProps) {
    if (!deviation) {
        return error !== undefined ? <Alert tone="warning">{error}</Alert> : null;
    }

    return (
        <Alert tone="warning" className="flex-col items-start">
            <span>
                La tasa que escribiste ({deviation.manual}) está {deviation.percent} % {deviation.above ? 'por encima' : 'por debajo'} de la tasa{' '}
                {deviation.sourceLabel} del día ({deviation.reference}). Revisa que no sea un error de tipeo.
            </span>
            <CheckboxField
                label={`Confirmo la tasa de ${deviation.manual}`}
                name="confirm_rate_deviation"
                checked={confirmed === deviation.token}
                onChange={(event) => onChange(event.target.checked ? deviation.token : null)}
                error={error}
            />
        </Alert>
    );
}

interface RateConfirmationValues {
    [key: string]: string | number | null;
    confirm_stale_rate: string | null;
    confirm_rate_deviation: string | null;
}

interface RateConfirmationsProps {
    preview: ApprovalPreviewView | null;
    values: RateConfirmationValues;
    onChange: (field: 'confirm_stale_rate' | 'confirm_rate_deviation', token: string | null) => void;
    errors: Partial<Record<string, string>>;
}

export function RateConfirmations({ preview, values, onChange, errors }: RateConfirmationsProps) {
    return (
        <>
            <StaleRateConfirmation
                staleRate={preview?.staleRate ?? null}
                confirmed={values.confirm_stale_rate}
                onChange={(token) => onChange('confirm_stale_rate', token)}
                error={errors.confirm_stale_rate}
            />
            <RateDeviationConfirmation
                deviation={preview?.rateDeviation ?? null}
                confirmed={values.confirm_rate_deviation}
                onChange={(token) => onChange('confirm_rate_deviation', token)}
                error={errors.confirm_rate_deviation}
            />
        </>
    );
}

export function staleRatePending(preview: ApprovalPreviewView | null, confirmed: string | null): boolean {
    return preview?.staleRate != null && confirmed !== preview.staleRate.token;
}

export function ratePending(preview: ApprovalPreviewView | null, values: RateConfirmationValues): boolean {
    const deviation = preview?.rateDeviation ?? null;

    return staleRatePending(preview, values.confirm_stale_rate) || (deviation !== null && values.confirm_rate_deviation !== deviation.token);
}
