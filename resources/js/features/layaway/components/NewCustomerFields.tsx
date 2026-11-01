import { CheckboxField } from '@/components/ui/checkbox-field';
import { DocumentField } from '@/components/ui/document-field';
import { PhoneField } from '@/components/ui/phone-field';
import { TextField } from '@/components/ui/text-field';

export type NewCustomerValues = {
    first_name: string;
    last_name: string;
    document_number: string;
    phone: string;
    email: string;
    terms_accepted: boolean;
};

interface NewCustomerFieldsProps {
    values: NewCustomerValues;
    onChange: (patch: Partial<NewCustomerValues>) => void;
    errors: Partial<Record<keyof NewCustomerValues, string>>;
    documentHint?: string;
    emailHint?: string;
}

export function NewCustomerFields({
    values,
    onChange,
    errors,
    documentHint = 'Opcional. Si ya tienes una ficha con este documento, el apartado va a esa ficha y no se cambian sus datos. Si no la tienes, el cliente la escribe al entrar con su código.',
    emailHint = 'Si lo dejas, le enviamos su código de apartado y cómo pagar.',
}: NewCustomerFieldsProps) {
    return (
        <div className="flex flex-col gap-1">
            <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                <TextField
                    label="Nombre"
                    name="first_name"
                    value={values.first_name}
                    onChange={(event) => onChange({ first_name: event.target.value })}
                    error={errors.first_name}
                    required
                />
                <TextField
                    label="Apellido (opcional)"
                    name="last_name"
                    value={values.last_name}
                    onChange={(event) => onChange({ last_name: event.target.value })}
                    error={errors.last_name}
                />
            </div>
            <DocumentField
                label="Cédula o RIF (opcional)"
                name="document_number"
                value={values.document_number}
                onChange={(value) => onChange({ document_number: value })}
                error={errors.document_number}
                hint={documentHint}
            />
            <PhoneField
                label="Teléfono celular (opcional)"
                name="phone"
                value={values.phone}
                onChange={(value) => onChange({ phone: value })}
                error={errors.phone}
            />
            <TextField
                label="Correo (opcional)"
                name="email"
                type="email"
                value={values.email}
                onChange={(event) => onChange({ email: event.target.value })}
                error={errors.email}
                hint={emailHint}
            />
            <CheckboxField
                label="El cliente aceptó las condiciones del apartado"
                name="terms_accepted"
                checked={values.terms_accepted}
                onChange={(event) => onChange({ terms_accepted: event.target.checked })}
                error={errors.terms_accepted}
                hint="Queda registrado que lo declaraste tú, con la fecha y desde dónde."
            />
        </div>
    );
}
