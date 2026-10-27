import { BankField } from '@/components/ui/bank-field';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { PhoneField } from '@/components/ui/phone-field';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type PaymentAccountView, type PaymentMethodDefinition } from '@/types/payment';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

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
    account?: PaymentAccountView;
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
        <form className="flex flex-col gap-1" onSubmit={submit} noValidate>
            <SelectField
                label="Método"
                name="method"
                options={methods.map((method) => ({ value: method.value, label: method.label }))}
                value={data.method}
                onChange={(event) => setData('method', event.target.value)}
                error={errors.method}
            />

            {selected?.isCash === false ? (
                <BankField label="Banco" name="bank" value={data.bank} onChange={(value) => setData('bank', value)} error={errors.bank} />
            ) : null}

            {data.method === 'pago_movil' ? (
                <PhoneField
                    key="pago-movil"
                    label={selected?.identifierLabel ?? 'Teléfono'}
                    name="identifier"
                    value={data.identifier}
                    onChange={(value) => setData('identifier', value)}
                    error={errors.identifier}
                    required
                />
            ) : (
                <TextField
                    label={selected?.identifierLabel ?? 'Identificador'}
                    name="identifier"
                    value={data.identifier}
                    onChange={(event) => setData('identifier', event.target.value)}
                    error={errors.identifier}
                    autoComplete="off"
                    required
                />
            )}

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

            <div>
                <CheckboxField
                    label="Ofrecer esta cuenta en los apartados nuevos"
                    name="is_active"
                    checked={data.is_active}
                    onChange={(event) => setData('is_active', event.target.checked)}
                    hint="Una cuenta desactivada deja de ofrecerse, pero conserva los comprobantes que ya la señalan."
                    error={errors.is_active}
                />
            </div>

            <div className="mt-2 flex gap-2 [&>*]:flex-1">
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
