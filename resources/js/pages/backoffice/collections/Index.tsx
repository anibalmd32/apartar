import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { COLLECTION_FILTER_LABELS, CollectionFilters } from '@/features/collections/components/CollectionFilters';
import { CollectionSummary } from '@/features/collections/components/CollectionSummary';
import { installmentColumns, layawayColumns } from '@/features/collections/components/collectionColumns';
import { useCollectionFilters } from '@/features/collections/hooks/useCollectionFilters';
import { DataTable } from '@/features/data-table/components/DataTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CollectionIndexProps, type CollectionInstallmentRow, type CollectionLayawayRow } from '@/types/collections';
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
            {props.kind === 'layaways' ? (
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
            Cuando apruebes la inicial de un apartado, sus cuotas aparecen aquí con la fecha y el teléfono del cliente para que le recuerdes.
        </EmptyState>
    );
}

function CollectionTable<TRow extends CollectionInstallmentRow | CollectionLayawayRow>(
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
                </>
            }
        />
    );
}
