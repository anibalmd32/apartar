import { CheckboxField } from '@/components/ui/checkbox-field';
import { DocumentField } from '@/components/ui/document-field';
import { PhoneField } from '@/components/ui/phone-field';
import { TextField } from '@/components/ui/text-field';

export interface NewCustomerValues {
    full_name: string;
    document_number: string;
    phone: string;
    email: string;
    terms_accepted: boolean;
}

interface NewCustomerFieldsProps {
    values: NewCustomerValues;
    onChange: (patch: Partial<NewCustomerValues>) => void;
    errors: Partial<Record<keyof NewCustomerValues, string>>;
}

export function NewCustomerFields({ values, onChange, errors }: NewCustomerFieldsProps) {
    return (
        <div className="flex flex-col gap-1">
            <TextField
                label="Nombre y apellido"
                name="full_name"
                value={values.full_name}
                onChange={(event) => onChange({ full_name: event.target.value })}
                error={errors.full_name}
                required
            />
            <DocumentField
                label="Cédula o RIF"
                name="document_number"
                value={values.document_number}
                onChange={(value) => onChange({ document_number: value })}
                error={errors.document_number}
                hint="Si ya tienes una ficha con este documento, el apartado va a esa ficha y no se cambian sus datos."
                required
            />
            <PhoneField
                label="Teléfono celular"
                name="phone"
                value={values.phone}
                onChange={(value) => onChange({ phone: value })}
                error={errors.phone}
                required
            />
            <TextField
                label="Correo (opcional)"
                name="email"
                type="email"
                value={values.email}
                onChange={(event) => onChange({ email: event.target.value })}
                error={errors.email}
                hint="Si lo dejas, le enviamos su código de apartado y cómo pagar."
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
