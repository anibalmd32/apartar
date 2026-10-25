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

export function staleRatePending(preview: ApprovalPreviewView | null, confirmed: string | null): boolean {
    return preview?.staleRate != null && confirmed !== preview.staleRate.token;
}
