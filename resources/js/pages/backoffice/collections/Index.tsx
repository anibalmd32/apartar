import { EmptyState } from '@/components/ui/empty-state';
import { PageActions } from '@/components/ui/page-actions';
import { COLLECTION_FILTER_LABELS, CollectionFilters } from '@/features/collections/components/CollectionFilters';
import { AwaitingDownPaymentLink, CollectionSummary } from '@/features/collections/components/CollectionSummary';
import { downPaymentColumns, installmentColumns, layawayColumns } from '@/features/collections/components/collectionColumns';
import { useCollectionFilters } from '@/features/collections/hooks/useCollectionFilters';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useExportAction } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { bo } from '@/lib/surfaces';
import {
    type CollectionDownPaymentRow,
    type CollectionIndexProps,
    type CollectionInstallmentRow,
    type CollectionLayawayRow,
} from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { cilPlus, cilWallet } from '@coreui/icons';
import { type ReactNode } from 'react';

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
            ) : (
                <CollectionTable key="installments" {...props} rows={props.rows} columns={installmentColumns} label="Cuotas por cobrar" />
            )}
        </BackofficeLayout>
    );
}

function emptyMessage(props: CollectionIndexProps, narrowed: boolean): ReactNode {
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

function CollectionTable<TRow extends CollectionInstallmentRow | CollectionLayawayRow | CollectionDownPaymentRow>(
    props: CollectionIndexProps & { rows: readonly TRow[]; columns: readonly DataTableColumn<TRow>[]; label: string },
) {
    const { table, filters, change } = useCollectionFilters({ rows: props.rows, meta: props.meta, columns: props.columns });
    const narrowed = Object.values(props.meta.filters).some((value) => value !== null);

    return (
        <DataTable
            label={props.label}
            table={table}
            filterLabels={COLLECTION_FILTER_LABELS}
            rowHref={(row) => row.layaway.url}
            empty={emptyMessage(props, narrowed)}
            toolbar={
                <>
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
                            {props.awaitingDownPayment ? <AwaitingDownPaymentLink awaiting={props.awaitingDownPayment} /> : null}
                        </div>
                    ) : null}
                </>
            }
        />
    );
}
