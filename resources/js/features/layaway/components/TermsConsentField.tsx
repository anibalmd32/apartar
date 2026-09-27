import { type LayawayConsentView } from '@/types/layaway';
import { useId } from 'react';

/**
 * La casilla de consentimiento del apartado (`REQ-IDN-005`).
 *
 * Obligatoria, y la valida el servidor (RB-1): esta casilla solo la pinta.
 * Dice siempre **con quién es el contrato** —el pago es con el negocio, no con
 * Apartar (RB-4)— y qué se guarda al marcarla: la fecha y la IP, como
 * constancia (RB-2).
 *
 * ## Los documentos de la plataforma, solo cuando existan
 *
 * `/terminos` y `/privacidad` están montadas y **sin redactar** (`T-037`), y
 * dicen de sí mismas que no tienen valor legal. Una casilla que dijera «acepto»
 * apuntando a una página que dice «esto no vale» sería peor que no enlazarlas.
 * Así que, mientras `platformTermsDrafted` sea falso, lo que se acepta es lo
 * que está en esta pantalla —el producto, el total, la inicial y las cuotas— y
 * con quién es el compromiso, y los documentos no se nombran. El día que se
 * redacten, la marca de `LegalController` cambia y la casilla los enlaza sin
 * tocar este archivo. Decidido en `T-045b`, escrito bajo `REQ-IDN-005`.
 *
 * Los enlaces abren en pestaña nueva, para no perder lo escrito (happy path
 * paso 1). Las condiciones propias del negocio no existen en v1 (SP-4).
 */

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
                    {consent.platformTermsDrafted ? (
                        <>
                            Acepto las condiciones de pago de este apartado, los{' '}
                            <a className="link" href="/terminos" target="_blank" rel="noopener noreferrer">
                                Términos de servicio
                            </a>{' '}
                            y la{' '}
                            <a className="link" href="/privacidad" target="_blank" rel="noopener noreferrer">
                                Política de privacidad
                            </a>{' '}
                            de Apartar.
                        </>
                    ) : (
                        <>Acepto apartar este producto con el plan de pago de arriba.</>
                    )}{' '}
                    El compromiso de pago es con <strong>{consent.businessName}</strong>, no con Apartar.
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
