import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { COLLECTION_FILTER_LABELS, CollectionFilters } from '@/features/collections/components/CollectionFilters';
import { CollectionSummary } from '@/features/collections/components/CollectionSummary';
import { downPaymentColumns, installmentColumns, layawayColumns } from '@/features/collections/components/collectionColumns';
import { useCollectionFilters } from '@/features/collections/hooks/useCollectionFilters';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { DataTable } from '@/features/data-table/components/DataTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import {
    type CollectionDownPaymentRow,
    type CollectionIndexProps,
    type CollectionInstallmentRow,
    type CollectionLayawayRow,
} from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { cilPlus, cilWallet } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export default function CollectionIndex(props: CollectionIndexProps) {
    return (
        <BackofficeLayout
            title="Cobranza"
            description="Lo que tienes por cobrar, cuota por cuota, con el teléfono de cada cliente a la vista."
            actions={
                <>
                    <ExportButton type="collections" filters={props.meta.filters} />
                    <Link href="/backoffice/apartados/nuevo" className="btn btn-primary">
                        <Icon icon={cilPlus} />
                        Nuevo apartado
                    </Link>
                </>
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
            empty={emptyMessage(props, narrowed)}
            toolbar={
                <>
                    <CollectionFilters filters={filters} options={props.options} onChange={change} counts={props.presetCounts} />
                    {props.summary ? <CollectionSummary summary={props.summary} /> : null}
                    {props.awaitingDownPayment ? (
                        <Link
                            href="/backoffice/cobranza?preset=awaiting_down_payment"
                            className="bg-warning-soft hover:border-line-strong flex flex-wrap items-center justify-between gap-2 rounded-xl border border-transparent px-[18px] py-3 text-sm"
                        >
                            <span>
                                <strong>
                                    {props.awaitingDownPayment.count === 1
                                        ? '1 apartado espera la inicial'
                                        : `${props.awaitingDownPayment.count} apartados esperan la inicial`}
                                </strong>
                                {' · '}
                                <MoneyLabel money={props.awaitingDownPayment.missing} /> por cobrar
                            </span>
                            <span className="text-primary-ink font-semibold">Ver cuáles →</span>
                        </Link>
                    ) : null}
                </>
            }
        />
    );
}
