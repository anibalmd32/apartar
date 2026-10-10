import { COLLECTION_FILTER_LABELS, CollectionFilters } from '@/features/collections/components/CollectionFilters';
import { installmentColumns, layawayColumns } from '@/features/collections/components/collectionColumns';
import { useCollectionFilters } from '@/features/collections/hooks/useCollectionFilters';
import { DataTable } from '@/features/data-table/components/DataTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CollectionIndexProps, type CollectionInstallmentRow, type CollectionLayawayRow } from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';

/**
 * La cobranza (`REQ-BOF-042`): la cartera del negocio, cuota por cuota, con
 * los filtros rápidos del día a día —vencen hoy, esta semana, el corte
 * quincenal, la mora larga— y los pagados sin entregar.
 *
 * Tabla server-driven (`REQ-BOF-045`) con paginación simple: el estado vive
 * en la URL y se comparte con el equipo (RB-7). La consulta es la de
 * `CollectionFilters`, la misma que usarán la exportación y los avisos.
 */
export default function CollectionIndex(props: CollectionIndexProps) {
    return (
        <BackofficeLayout title="Cobranza" description="Lo que tienes por cobrar, cuota por cuota, con el teléfono de cada cliente a la vista.">
            {/* La clave por forma de fila: pasar de cuotas a apartados es otra tabla, no la misma con otras filas. */}
            {props.kind === 'layaways' ? (
                <CollectionTable key="layaways" {...props} rows={props.rows} columns={layawayColumns} label="Apartados pagados sin entregar" />
            ) : (
                <CollectionTable key="installments" {...props} rows={props.rows} columns={installmentColumns} label="Cuotas por cobrar" />
            )}
        </BackofficeLayout>
    );
}

/**
 * La frase del estado vacío (`REQ-BOF-045` RB-7, SP-1): con un preset, su
 * definición exacta; sin filtros, lo que significa una cartera vacía.
 */
function emptyMessage(props: CollectionIndexProps, narrowed: boolean): string {
    if (props.meta.page > 1) {
        return 'No hay más filas en esta página. Vuelve a la primera.';
    }

    if (props.definition !== null) {
        return `Nada cumple este filtro ahora mismo. ${props.definition}`;
    }

    return narrowed
        ? 'Ninguna cuota cumple estos filtros.'
        : 'No tienes cuotas por cobrar. Cuando un apartado se active, sus cuotas aparecerán aquí.';
}

function CollectionTable<TRow extends CollectionInstallmentRow | CollectionLayawayRow>(
    props: CollectionIndexProps & { rows: readonly TRow[]; columns: readonly DataTableColumn<TRow>[]; label: string },
) {
    const { table, filters, change } = useCollectionFilters({ rows: props.rows, meta: props.meta, columns: props.columns });
    const narrowed = Object.values(props.meta.filters).some((value) => value !== null);

    return (
        <section className="card bg-base-100 shadow-sm">
            <div className="card-body gap-4">
                <ExportButton type="collections" filters={props.meta.filters} />
                <DataTable
                    label={props.label}
                    table={table}
                    filterLabels={COLLECTION_FILTER_LABELS}
                    empty={emptyMessage(props, narrowed)}
                    toolbar={<CollectionFilters filters={filters} options={props.options} onChange={change} />}
                />
            </div>
        </section>
    );
}
