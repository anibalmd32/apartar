import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type PaymentAccountView, type PaymentMethodDefinition } from '@/types/payment';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

/**
 * El formulario de una cuenta de cobro, para el alta y para la edición
 * (`REQ-ORG-007`).
 *
 * **Uno solo para las dos cosas**, y no por ahorrar líneas: los campos, sus
 * reglas y sus etiquetas son idénticos, y dos formularios serían dos sitios
 * donde recordar que el `identifier` cambia de nombre con el método. Lo único
 * que cambia es a dónde se envía.
 *
 * **La etiqueta del identificador la manda el servidor** (RB-2), y es lo que
 * evita que la pantalla diga «identificador» seis veces: en pago móvil pide un
 * teléfono, en transferencia veinte dígitos, en Zelle un correo. Sale de
 * `PaymentMethod::identifierLabel()`; escribirla aquí sería un segundo censo de
 * los seis casos del enum, y el que se queda viejo es siempre este.
 *
 * El banco se pide solo cuando el método lo tiene detrás: en efectivo no hay
 * ninguno, y un campo vacío que nunca se rellena enseña a saltarse los campos.
 */

interface AccountForm {
    [key: string]: string | boolean;
    method: string;
    bank: string;
    holder_name: string;
    holder_document: string;
    identifier: string;
    instructions: string;
    is_active: boolean;
}

interface PaymentAccountFormProps {
    methods: readonly PaymentMethodDefinition[];
    /** La cuenta que se edita; sin ella el formulario da de alta. */
    account?: PaymentAccountView;
    /** El plan no admite otra cuenta activa: dar de alta quedaría bloqueado. */
    atLimit?: boolean;
    onDone?: () => void;
}

function initialForm(methods: readonly PaymentMethodDefinition[], account?: PaymentAccountView): AccountForm {
    return {
        method: account?.method ?? methods[0]?.value ?? 'pago_movil',
        bank: account?.bank ?? '',
        holder_name: account?.holderName ?? '',
        holder_document: account?.holderDocument ?? '',
        identifier: account?.identifier ?? '',
        instructions: account?.instructions ?? '',
        is_active: account?.isActive ?? true,
    };
}

export function PaymentAccountForm({ methods, account, atLimit = false, onDone }: PaymentAccountFormProps) {
    const { data, setData, post, put, processing, errors, reset } = useForm<AccountForm>(initialForm(methods, account));

    const selected = methods.find((method) => method.value === data.method);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                if (account === undefined) {
                    reset();
                }

                onDone?.();
            },
        };

        if (account === undefined) {
            post('/backoffice/cuentas-de-cobro', options);
        } else {
            put(`/backoffice/cuentas-de-cobro/${account.id}`, options);
        }
    };

    return (
        <form className="grid gap-2 sm:grid-cols-2" onSubmit={submit} noValidate>
            <SelectField
                label="Método"
                name="method"
                options={methods.map((method) => ({ value: method.value, label: method.label }))}
                value={data.method}
                onChange={(event) => setData('method', event.target.value)}
                error={errors.method}
            />

            {/* En efectivo no hay banco detrás: pedirlo sería un campo que
                nunca se rellena, y los campos que nunca se rellenan enseñan a
                saltárselos. */}
            {selected?.isCash === false ? (
                <TextField
                    label="Banco"
                    name="bank"
                    value={data.bank}
                    onChange={(event) => setData('bank', event.target.value)}
                    error={errors.bank}
                    autoComplete="off"
                />
            ) : null}

            <TextField
                label={selected?.identifierLabel ?? 'Identificador'}
                name="identifier"
                value={data.identifier}
                onChange={(event) => setData('identifier', event.target.value)}
                error={errors.identifier}
                autoComplete="off"
                required
            />

            {/* RB-3 · en pago móvil el titular y su cédula son parte del dato
                de pago: sin ellos el cliente no puede completar la
                transferencia. Por eso van como obligatorios y no como «datos
                adicionales». */}
            <TextField
                label="Titular"
                name="holder_name"
                value={data.holder_name}
                onChange={(event) => setData('holder_name', event.target.value)}
                error={errors.holder_name}
                autoComplete="off"
                required
            />

            <TextField
                label="Cédula o RIF del titular"
                name="holder_document"
                value={data.holder_document}
                onChange={(event) => setData('holder_document', event.target.value)}
                error={errors.holder_document}
                autoComplete="off"
                required
            />

            <TextField
                label="Instrucciones para el cliente"
                name="instructions"
                value={data.instructions}
                onChange={(event) => setData('instructions', event.target.value)}
                error={errors.instructions}
                hint="Opcional, hasta 300 caracteres. Por ejemplo: «indica tu cédula en la referencia»."
                maxLength={300}
                autoComplete="off"
            />

            <div className="sm:col-span-2">
                <CheckboxField
                    label="Ofrecer esta cuenta en los apartados nuevos"
                    name="is_active"
                    checked={data.is_active}
                    onChange={(event) => setData('is_active', event.target.checked)}
                    hint="Una cuenta desactivada deja de ofrecerse, pero conserva los comprobantes que ya la señalan."
                    error={errors.is_active}
                />
            </div>

            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit" loading={processing} disabled={account === undefined && atLimit && data.is_active}>
                    {account === undefined ? 'Añadir cuenta' : 'Guardar cambios'}
                </Button>

                {onDone === undefined ? null : (
                    <Button type="button" variant="ghost" onClick={onDone}>
                        Cancelar
                    </Button>
                )}
            </div>
        </form>
    );
}
