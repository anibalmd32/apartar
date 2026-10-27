import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { PhoneField } from '@/components/ui/phone-field';
import { TextField } from '@/components/ui/text-field';
import { QuantityStepper } from '@/features/layaway/components/QuantityStepper';
import { TermsConsentField } from '@/features/layaway/components/TermsConsentField';
import { type LayawayForm as LayawayFormState } from '@/features/layaway/hooks/useLayawayForm';
import { formatDocumentNumber } from '@/lib/identity-format';
import { type LayawayConsentView } from '@/types/layaway';
import { type FormEventHandler } from 'react';

interface LayawayFormProps {
    form: LayawayFormState;
    emailLocked?: boolean;
    maxQuantity: number;
    consent: LayawayConsentView;
}

export function LayawayForm({ form, maxQuantity, consent, emailLocked = false }: LayawayFormProps) {
    const { data, setData, processing } = form;

    const errors = form.errors as Partial<Record<string, string>>;

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();
        form.submit();
    };

    return (
        <form className="flex flex-col gap-2" onSubmit={onSubmit} noValidate>
            {errors.catalog ? <Alert tone="warning">{errors.catalog}</Alert> : null}
            {errors.product ? <Alert tone="warning">{errors.product}</Alert> : null}

            <QuantityStepper
                value={data.quantity}
                max={maxQuantity}
                onChange={form.setQuantity}
                error={errors.quantity}
                hint={maxQuantity > 1 ? `Hasta ${maxQuantity} unidades.` : 'Queda una unidad.'}
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

            <PhoneField
                label="Teléfono celular"
                name="phone"
                value={data.phone}
                onChange={(value) => setData('phone', value)}
                error={errors.phone}
                hint="Es el número con el que el negocio te contactará."
                required
            />

            <TextField
                label="Correo (opcional)"
                name="email"
                type="email"
                value={data.email}
                onChange={(event) => setData('email', event.target.value)}
                error={errors.email}
                readOnly={emailLocked}
                hint={
                    emailLocked
                        ? 'Es el de tu cuenta de Google: ahí te enviamos el código y cómo pagar.'
                        : 'Si lo dejas, te enviamos el código y cómo pagar.'
                }
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
