import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/text-field';
import { TermsConsentField } from '@/features/layaway/components/TermsConsentField';
import { type LayawayForm as LayawayFormState } from '@/features/layaway/hooks/useLayawayForm';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { type LayawayConsentView } from '@/types/layaway';
import { type FormEventHandler } from 'react';

/**
 * Los campos del apartado (`REQ-LAY-001` RB-3, RB-5; `REQ-IDN-005`).
 *
 * Nombre, documento, teléfono y la casilla son obligatorios; el correo no.
 * **Cada rechazo del servidor aterriza en su campo** (SP-1 a SP-6), y los que
 * no tienen campo —el catálogo se cerró, el negocio no puede recibir
 * apartados, el producto se retiró— van arriba del formulario. Inertia
 * conserva lo escrito en todos los casos: quien está a punto de comprometerse
 * a pagar no puede recibir la pantalla en blanco.
 *
 * Las máscaras de documento y teléfono solo dan forma (`identity-format`): no
 * rechazan nada. Quien decide es el servidor.
 */

interface LayawayFormProps {
    form: LayawayFormState;
    maxQuantity: number;
    consent: LayawayConsentView;
}

export function LayawayForm({ form, maxQuantity, consent }: LayawayFormProps) {
    const { data, setData, processing } = form;

    // `catalog` y `product` no son campos del formulario: son los rechazos que
    // no tienen dónde aterrizar, y se pintan arriba.
    const errors = form.errors as Partial<Record<string, string>>;

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();
        form.submit();
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={onSubmit} noValidate>
            {errors.catalog ? <Alert tone="warning">{errors.catalog}</Alert> : null}
            {errors.product ? <Alert tone="warning">{errors.product}</Alert> : null}

            <TextField
                label="Cantidad"
                name="quantity"
                type="number"
                inputMode="numeric"
                min={1}
                max={maxQuantity}
                value={data.quantity}
                onChange={(event) => form.setQuantity(Number(event.target.value))}
                error={errors.quantity}
                hint={maxQuantity > 1 ? `Hasta ${maxQuantity} unidades.` : 'Queda una unidad.'}
                required
            />

            <TextField
                label="Nombre y apellido"
                name="fullName"
                value={data.fullName}
                onChange={(event) => setData('fullName', event.target.value)}
                error={errors.fullName}
                autoComplete="name"
                required
            />

            <TextField
                label="Cédula o RIF"
                name="documentNumber"
                value={data.documentNumber}
                onChange={(event) => setData('documentNumber', formatDocumentNumber(event.target.value))}
                error={errors.documentNumber}
                hint="Con ella, y con tu código de apartado, podrás consultar tu apartado."
                autoComplete="off"
                required
            />

            <TextField
                label="Teléfono"
                name="phone"
                type="tel"
                inputMode="tel"
                value={data.phone}
                onChange={(event) => setData('phone', formatPhone(event.target.value))}
                error={errors.phone}
                hint={
                    form.unrecognisedPrefix
                        ? 'No reconocemos ese prefijo, ¿es correcto? Si lo es, sigue: tu apartado se guarda igual.'
                        : 'Es el número con el que el negocio te contactará.'
                }
                autoComplete="tel"
                required
            />

            <TextField
                label="Correo (opcional)"
                name="email"
                type="email"
                value={data.email}
                onChange={(event) => setData('email', event.target.value)}
                error={errors.email}
                hint="Si lo dejas, te enviamos el código y cómo pagar."
                autoComplete="email"
            />

            <TermsConsentField
                checked={data.termsAccepted}
                onChange={(checked) => setData('termsAccepted', checked)}
                consent={consent}
                error={errors.termsAccepted}
            />

            {errors.idempotencyKey ? <Alert tone="error">{errors.idempotencyKey}</Alert> : null}

            <Button type="submit" variant="primary" block disabled={processing}>
                {processing ? 'Apartando…' : 'Apartar'}
            </Button>
        </form>
    );
}
