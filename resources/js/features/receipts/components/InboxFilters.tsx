import { DateRangeField } from '@/components/ui/date-range-field';
import { FilterBar } from '@/components/ui/filter-bar';
import { type FilterTab, FilterTabs } from '@/components/ui/filter-tabs';
import { SelectField } from '@/components/ui/select-field';
import { ToggleField } from '@/components/ui/toggle-field';
import { useFilterDraft } from '@/hooks/use-filter-draft';
import { dateChip, flagChip, optionChip, present } from '@/lib/filter-chips';
import { type ReceiptInboxFilters, type ReceiptInboxProps } from '@/types/receipt';

type SheetKey = 'method' | 'bank' | 'account' | 'from' | 'to' | 'cancelled';
type SheetValues = Pick<ReceiptInboxFilters, SheetKey>;
type Options = ReceiptInboxProps['options'];

interface InboxFiltersProps {
    filters: ReceiptInboxFilters;
    options: Options;
    onChange: (key: keyof ReceiptInboxFilters, value: string | null) => void;
    onChangeMany: (patch: Partial<Record<keyof ReceiptInboxFilters, string | null>>) => void;
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

const CLEARED: Record<SheetKey, null> = { method: null, bank: null, account: null, from: null, to: null, cancelled: null };

function accountOptions(options: Options) {
    return options.paymentAccounts.map((account) => ({
        value: String(account.id),
        label: `${account.methodLabel} · ${account.identifier}${account.isActive ? '' : ' (desactivada)'}`,
    }));
}

function Fields({
    values,
    options,
    onPick,
    stacked = false,
}: {
    values: SheetValues;
    options: Options;
    onPick: (key: SheetKey, value: string | null) => void;
    stacked?: boolean;
}) {
    const pick = (key: SheetKey) => (event: { target: { value: string } }) => onPick(key, event.target.value === '' ? null : event.target.value);
    const all = (label: string, fallback: string) => ({ value: '', label: stacked ? fallback : label });

    return (
        <>
            <SelectField
                label="Método"
                hideLabel={!stacked}
                inline={!stacked}
                name="method"
                options={[all('Método', 'Todos'), ...options.methods]}
                value={values.method ?? ''}
                onChange={pick('method')}
            />
            <SelectField
                label="Banco"
                hideLabel={!stacked}
                inline={!stacked}
                name="bank"
                options={[all('Banco', 'Todos'), ...options.banks.map((bank) => ({ value: bank, label: bank }))]}
                value={values.bank ?? ''}
                onChange={pick('bank')}
            />
            <SelectField
                label="Cuenta de cobro"
                hideLabel={!stacked}
                inline={!stacked}
                name="account"
                className={stacked ? undefined : 'lg:max-w-64'}
                options={[all('Cuenta de cobro', 'Todas'), ...accountOptions(options)]}
                value={values.account ?? ''}
                onChange={pick('account')}
            />
            <DateRangeField
                label="Fecha de llegada"
                fromLabel="Llegó desde"
                toLabel="Llegó hasta"
                showLabel={stacked}
                from={values.from ?? ''}
                to={values.to ?? ''}
                onFromChange={pick('from')}
                onToChange={pick('to')}
            />
            <ToggleField
                stacked={stacked}
                className={stacked ? undefined : 'lg:ml-auto'}
                checked={values.cancelled === '1'}
                onChange={(on) => onPick('cancelled', on ? '1' : null)}
            >
                Incluir los de apartados cancelados
            </ToggleField>
        </>
    );
}

export function InboxFilters({ filters, options, onChange, onChangeMany, pending = null, counts = {} }: InboxFiltersProps) {
    const applied: SheetValues = {
        method: filters.method,
        bank: filters.bank,
        account: filters.account,
        from: filters.from,
        to: filters.to,
        cancelled: filters.cancelled,
    };
    const sheet = useFilterDraft(applied);
    const chips = present([
        optionChip('method', 'Método', filters.method, options.methods),
        optionChip(
            'bank',
            'Banco',
            filters.bank,
            options.banks.map((bank) => ({ value: bank, label: bank })),
        ),
        optionChip('account', 'Cuenta', filters.account, accountOptions(options)),
        dateChip('from', 'Llegó desde', filters.from),
        dateChip('to', 'Llegó hasta', filters.to),
        flagChip('cancelled', 'Con apartados cancelados', filters.cancelled === '1'),
    ]);

    const tabs: FilterTab<string | null>[] = [
        { value: null, label: 'Todos' },
        ...options.statuses.map((status) => ({
            value: status.value,
            label: STATUS_TAB_LABELS[status.value] ?? status.label,
            count: status.value === 'pending_review' ? (pending ?? counts[status.value] ?? null) : (counts[status.value] ?? null),
        })),
    ];

    return (
        <div className="flex flex-col gap-3 lg:gap-4">
            <FilterTabs label="Estado" tabs={tabs} value={filters.status} onChange={(value) => onChange('status', value)} />

            <FilterBar
                inline={<Fields values={applied} options={options} onPick={onChange} />}
                sheet={<Fields values={sheet.draft} options={options} onPick={sheet.set} stacked />}
                chips={chips.map((chip) => ({ ...chip, onRemove: () => onChange(chip.key, null) }))}
                onOpen={sheet.reset}
                onApply={() => onChangeMany(sheet.changes())}
                onClear={() => onChangeMany(CLEARED)}
            />
        </div>
    );
}
