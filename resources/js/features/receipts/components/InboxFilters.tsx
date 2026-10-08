import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type ReceiptInboxFilters, type ReceiptInboxProps } from '@/types/receipt';

/**
 * Los filtros de la bandeja (contrato de `REQ-PAY-004`): estado, método,
 * banco, cuenta de cobro, rango de fechas de llegada y, aparte, los apartados
 * cancelados (RB-8).
 *
 * Pintan lo que el servidor **aplicó** (`meta.filters`): un filtro ignorado
 * vuelve vacío, y el aviso de por qué lo pone la tabla (`REQ-BOF-045` SP-4).
 * Cambiar uno es cambiar la query string, y eso lo hace `useServerTable`.
 */

interface InboxFiltersProps {
    filters: ReceiptInboxFilters;
    options: ReceiptInboxProps['options'];
    onChange: (key: keyof ReceiptInboxFilters, value: string | null) => void;
}

/** Nombres de los filtros para el aviso de la tabla. */
export const INBOX_FILTER_LABELS: Readonly<Record<keyof ReceiptInboxFilters, string>> = {
    status: 'estado',
    method: 'método',
    bank: 'banco',
    account: 'cuenta de cobro',
    from: 'desde',
    to: 'hasta',
    cancelled: 'apartados cancelados',
};

export function InboxFilters({ filters, options, onChange }: InboxFiltersProps) {
    const pick = (key: keyof ReceiptInboxFilters) => (event: { target: { value: string } }) =>
        onChange(key, event.target.value === '' ? null : event.target.value);

    return (
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <SelectField
                label="Estado"
                name="status"
                options={[{ value: '', label: 'Todos' }, ...options.statuses]}
                value={filters.status ?? ''}
                onChange={pick('status')}
            />
            <SelectField
                label="Método"
                name="method"
                options={[{ value: '', label: 'Todos' }, ...options.methods]}
                value={filters.method ?? ''}
                onChange={pick('method')}
            />
            <SelectField
                label="Banco"
                name="bank"
                options={[{ value: '', label: 'Todos' }, ...options.banks.map((bank) => ({ value: bank, label: bank }))]}
                value={filters.bank ?? ''}
                onChange={pick('bank')}
            />
            <SelectField
                label="Cuenta de cobro"
                name="account"
                options={[
                    { value: '', label: 'Todas' },
                    ...options.paymentAccounts.map((account) => ({
                        value: String(account.id),
                        label: `${account.methodLabel} · ${account.identifier}${account.isActive ? '' : ' (desactivada)'}`,
                    })),
                ]}
                value={filters.account ?? ''}
                onChange={pick('account')}
            />
            <TextField label="Llegó desde" name="from" type="date" value={filters.from ?? ''} onChange={pick('from')} />
            <TextField label="Llegó hasta" name="to" type="date" value={filters.to ?? ''} onChange={pick('to')} />

            <label className="label cursor-pointer gap-2 sm:col-span-2 lg:col-span-3">
                <input
                    type="checkbox"
                    className="checkbox checkbox-sm"
                    checked={filters.cancelled === '1'}
                    onChange={(event) => onChange('cancelled', event.target.checked ? '1' : null)}
                />
                <span className="label-text">Incluir los de apartados cancelados</span>
            </label>
        </div>
    );
}
