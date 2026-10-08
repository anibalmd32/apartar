import { Alert } from '@/components/ui/alert';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { type ApprovalPreviewView } from '@/types/receipt';

interface StaleRateConfirmationProps {
    staleRate: ApprovalPreviewView['staleRate'];
    /** La tasa confirmada (fuente, valor y publicación), o `null`. */
    confirmed: string | null;
    onChange: (token: string | null) => void;
    error: string | undefined;
}

/**
 * La tasa de más de 30 días (`REQ-FX-006` SP-5): convertir con ella se
 * permite, pero se confirma. Lo confirmado es **esa** publicación: si la tasa
 * cambia, la casilla se desmarca sola, y el servidor compara lo mismo.
 *
 * La comparten la aprobación y el pago manual, que convierten igual. Si el
 * servidor la pide y la vista previa no la traía —la tasa cumplió los 30 días
 * entre medias—, el formulario vuelve a pedir la vista previa con el error
 * (`useApprovalPreview`, `revision`) y la casilla aparece.
 */
export function StaleRateConfirmation({ staleRate, confirmed, onChange, error }: StaleRateConfirmationProps) {
    if (staleRate === null) {
        // El servidor vio una tasa vieja que esta vista previa no enseña: se dice igual.
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

/** Si la vista previa pide confirmar la tasa y no está confirmada. */
export function staleRatePending(preview: ApprovalPreviewView | null, confirmed: string | null): boolean {
    return preview?.staleRate != null && confirmed !== preview.staleRate.token;
}
