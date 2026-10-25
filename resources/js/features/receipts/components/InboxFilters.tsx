import { type FilterTab, FilterTabs } from '@/components/ui/filter-tabs';
import { SelectField } from '@/components/ui/select-field';
import { type ReceiptInboxFilters, type ReceiptInboxProps } from '@/types/receipt';

interface InboxFiltersProps {
    filters: ReceiptInboxFilters;
    options: ReceiptInboxProps['options'];
    onChange: (key: keyof ReceiptInboxFilters, value: string | null) => void;
    pending?: number | null;
    counts?: Readonly<Partial<Record<string, number>>>;
}

const STATUS_TAB_LABELS: Readonly<Record<string, string>> = {
    pending_review: 'Por revisar',
    approved: 'Aprobados',
    rejected: 'Rechazados',
};

export const INBOX_FILTER_LABELS: Readonly<Record<keyof ReceiptInboxFilters, string>> = {
    status: 'estado',
    method: 'método',
    bank: 'banco',
    account: 'cuenta de cobro',
    from: 'desde',
    to: 'hasta',
    cancelled: 'apartados cancelados',
};

export function InboxFilters({ filters, options, onChange, pending = null, counts = {} }: InboxFiltersProps) {
    const pick = (key: keyof ReceiptInboxFilters) => (event: { target: { value: string } }) =>
        onChange(key, event.target.value === '' ? null : event.target.value);

    const tabs: FilterTab<string | null>[] = [
        ...options.statuses.map((status) => ({
            value: status.value,
            label: STATUS_TAB_LABELS[status.value] ?? status.label,
            count: status.value === 'pending_review' ? (pending ?? counts[status.value] ?? null) : (counts[status.value] ?? null),
        })),
        { value: null, label: 'Todos' },
    ];

    return (
        <div className="flex flex-col gap-4">
            <FilterTabs label="Estado" tabs={tabs} value={filters.status} onChange={(value) => onChange('status', value)} />

            <div className="flex flex-wrap items-center gap-2">
                <SelectField
                    label="Método"
                    hideLabel
                    inline
                    name="method"
                    options={[{ value: '', label: 'Método' }, ...options.methods]}
                    value={filters.method ?? ''}
                    onChange={pick('method')}
                />
                <SelectField
                    label="Banco"
                    hideLabel
                    inline
                    name="bank"
                    options={[{ value: '', label: 'Banco' }, ...options.banks.map((bank) => ({ value: bank, label: bank }))]}
                    value={filters.bank ?? ''}
                    onChange={pick('bank')}
                />
                <SelectField
                    label="Cuenta de cobro"
                    hideLabel
                    inline
                    name="account"
                    className="max-w-64"
                    options={[
                        { value: '', label: 'Cuenta de cobro' },
                        ...options.paymentAccounts.map((account) => ({
                            value: String(account.id),
                            label: `${account.methodLabel} · ${account.identifier}${account.isActive ? '' : ' (desactivada)'}`,
                        })),
                    ]}
                    value={filters.account ?? ''}
                    onChange={pick('account')}
                />
                <label className="input w-auto">
                    <span className="text-ink-3 text-[13px]">Llegó desde</span>
                    <input name="from" type="date" value={filters.from ?? ''} onChange={pick('from')} />
                </label>
                <label className="input w-auto">
                    <span className="text-ink-3 text-[13px]">hasta</span>
                    <input name="to" type="date" aria-label="Llegó hasta" value={filters.to ?? ''} onChange={pick('to')} />
                </label>

                <label className="text-ink-2 flex cursor-pointer items-center gap-2.5 text-[13px] font-medium lg:ml-auto">
                    <input
                        type="checkbox"
                        className="toggle toggle-sm"
                        checked={filters.cancelled === '1'}
                        onChange={(event) => onChange('cancelled', event.target.checked ? '1' : null)}
                    />
                    Incluir los de apartados cancelados
                </label>
            </div>
        </div>
    );
}
