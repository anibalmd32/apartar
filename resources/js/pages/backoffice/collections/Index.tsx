import { EmptyState } from '@/components/ui/empty-state';
import { PageActions } from '@/components/ui/page-actions';
import { downPaymentColumns, installmentColumns, layawayColumns } from '@/features/collections/components/collectionColumns';
import { COLLECTION_FILTER_LABELS, CollectionFilters } from '@/features/collections/components/CollectionFilters';
import { customerColumns, CustomerInstallments } from '@/features/collections/components/collectionGroups';
import { AwaitingDownPaymentLink, CollectionSummary } from '@/features/collections/components/CollectionSummary';
import { GroupingSwitch } from '@/features/collections/components/GroupingSwitch';
import { useCollectionFilters } from '@/features/collections/hooks/useCollectionFilters';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useExportAction } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { bo } from '@/lib/surfaces';
import {
    type CollectionCustomerRow,
    type CollectionDownPaymentRow,
    type CollectionIndexProps,
    type CollectionInstallmentRow,
    type CollectionLayawayRow,
} from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { cilPlus, cilWallet } from '@coreui/icons';
import { type ReactNode, useState } from 'react';

export default function CollectionIndex(props: CollectionIndexProps) {
    const exportAction = useExportAction('collections', props.meta.filters);

    return (
        <BackofficeLayout
            title="Cobranza"
            description="Lo que tienes por cobrar, cuota por cuota, con el teléfono de cada cliente a la vista."
            actions={
                <PageActions
                    actions={[
                        exportAction,
                        { label: 'Nuevo apartado', icon: cilPlus, href: bo('/apartados/nuevo'), variant: 'primary', primary: true },
                    ]}
                />
            }
        >
            {props.kind === 'down_payments' ? (
                <CollectionTable
                    key="down_payments"
                    {...props}
                    rows={props.rows}
                    columns={downPaymentColumns}
                    label="Apartados esperando la inicial"
                />
            ) : props.kind === 'layaways' ? (
                <CollectionTable key="layaways" {...props} rows={props.rows} columns={layawayColumns} label="Apartados pagados sin entregar" />
            ) : props.kind === 'customers' ? (
                <CustomerGroupTable {...props} rows={props.rows} />
            ) : (
                <CollectionTable key="installments" {...props} rows={props.rows} columns={installmentColumns} label="Cuotas por cobrar" />
            )}
        </BackofficeLayout>
    );
}

function emptyMessage(props: Pick<CollectionIndexProps, 'meta' | 'definition'>, narrowed: boolean): ReactNode {
    if (props.meta.page > 1) {
        return 'No hay más filas en esta página. Vuelve a la primera.';
    }

    if (props.definition !== null) {
        return `Nada cumple este filtro ahora mismo. ${props.definition}`;
    }

    return narrowed ? (
        'Ninguna cuota cumple estos filtros.'
    ) : (
        <EmptyState icon={cilWallet} title="Nada por cobrar todavía">
            Aquí aparecen las cuotas por cobrar, con la fecha y el teléfono del cliente para que le recuerdes. Los apartados que esperan la inicial
            están en el filtro «Esperando inicial».
        </EmptyState>
    );
}

type CollectionTableBase = Omit<CollectionIndexProps, 'rows' | 'kind'>;

function CustomerGroupTable(props: CollectionTableBase & { rows: readonly CollectionCustomerRow[] }) {
    const [open, setOpen] = useState<ReadonlySet<number>>(new Set());
    const toggle = (id: number) =>
        setOpen((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <CollectionTable
            key="customers"
            {...props}
            rows={props.rows}
            columns={customerColumns((id) => open.has(id), toggle)}
            label="Clientes con cuotas por cobrar"
            rowHref={(row) => row.customer?.url}
            expanded={(row) => (open.has(row.id) ? <CustomerInstallments rows={row.installments} /> : null)}
        />
    );
}

function CollectionTable<TRow extends CollectionInstallmentRow | CollectionLayawayRow | CollectionDownPaymentRow | CollectionCustomerRow>(
    props: CollectionTableBase & {
        rows: readonly TRow[];
        columns: readonly DataTableColumn<TRow>[];
        label: string;
        rowHref?: (row: TRow) => string | null | undefined;
        expanded?: (row: TRow) => ReactNode;
    },
) {
    const { table, filters, change } = useCollectionFilters({ rows: props.rows, meta: props.meta, columns: props.columns });
    const narrowed = Object.values(props.meta.filters).some((value) => value !== null);

    return (
        <DataTable
            label={props.label}
            table={table}
            filterLabels={COLLECTION_FILTER_LABELS}
            rowHref={props.rowHref ?? ((row) => row.layaway.url)}
            expanded={props.expanded}
            empty={emptyMessage(props, narrowed)}
            toolbar={
                <>
                    {props.groupingUrl ? <GroupingSwitch url={props.groupingUrl} grouped={props.grouped === true} /> : null}
                    <CollectionFilters
                        filters={filters}
                        options={props.options}
                        onChange={change}
                        onChangeMany={table.setFilters}
                        counts={props.presetCounts}
                    />
                    {props.summary || props.awaitingDownPayment ? (
                        <div className="flex flex-col gap-2 lg:gap-3">
                            {props.summary ? <CollectionSummary summary={props.summary} /> : null}
                            {props.awaitingDownPayment ? (
                                <AwaitingDownPaymentLink
                                    awaiting={props.awaitingDownPayment}
                                    narrowing={props.meta.filters as Record<string, string | null>}
                                />
                            ) : null}
                        </div>
                    ) : null}
                </>
            }
        />
    );
}
