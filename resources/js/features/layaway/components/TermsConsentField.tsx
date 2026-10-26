import { type LayawayConsentView } from '@/types/layaway';
import { useId } from 'react';

interface TermsConsentFieldProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    consent: LayawayConsentView;
    error?: string;
}

export function TermsConsentField({ checked, onChange, consent, error }: TermsConsentFieldProps) {
    const id = useId();
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;

    return (
        <fieldset className="fieldset w-full">
            <label className="label cursor-pointer items-start justify-start gap-3 whitespace-normal" htmlFor={id}>
                <input
                    id={id}
                    name="termsAccepted"
                    type="checkbox"
                    className={`checkbox mt-0.5 ${error ? 'checkbox-error' : ''}`}
                    checked={checked}
                    onChange={(event) => onChange(event.target.checked)}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={error ? `${hintId} ${errorId}` : hintId}
                    required
                />
                <span className="text-sm">
                    Acepto las condiciones de pago de este apartado, los{' '}
                    <a className="link" href="/terminos" target="_blank" rel="noopener noreferrer">
                        Términos de servicio
                    </a>{' '}
                    y la{' '}
                    <a className="link" href="/privacidad" target="_blank" rel="noopener noreferrer">
                        Política de privacidad
                    </a>{' '}
                    de Apartar. El compromiso de pago es con <strong>{consent.businessName}</strong>, no con Apartar.
                </span>
            </label>

            <p id={hintId} className="label text-xs whitespace-normal">
                Guardamos la fecha y la IP de esta aceptación como constancia.
            </p>

            {error ? (
                <p id={errorId} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
