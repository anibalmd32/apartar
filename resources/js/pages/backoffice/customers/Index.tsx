import { DIRECTORY_FILTER_LABELS, DirectoryFilters } from '@/features/customers/components/DirectoryFilters';
import { directoryColumns } from '@/features/customers/components/directoryColumns';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useDebouncedFilter } from '@/features/data-table/hooks/useDebouncedFilter';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CustomerDirectoryFilters, type CustomerIndexProps } from '@/types/customers';

/**
 * El directorio de clientes (`REQ-BOF-040`): buscar a cualquiera por su cédula,
 * su nombre, su teléfono o su correo, para atenderlo cuando escribe o llama.
 *
 * Tabla server-driven (`REQ-BOF-045`) con paginación simple: miles de clientes
 * no se cuentan (SP-5). El orden es el de la cobranza (RB-6): más cuotas
 * vencidas primero, después el vencimiento más cercano.
 */
export default function CustomerIndex({ rows, meta, options }: CustomerIndexProps) {
    const table = useServerTable({ rows, meta, columns: directoryColumns });
    const filters = meta.filters as unknown as CustomerDirectoryFilters;
    const [search, setSearch] = useDebouncedFilter(filters.q, (value) => table.setFilter('q', value));
    const narrowed = Object.values(meta.filters).some((value) => value !== null);

    return (
        <BackofficeLayout title="Clientes" description="Busca a tus clientes por cédula, teléfono, correo o nombre.">
            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <ExportButton type="customers" filters={meta.filters} />
                    <DataTable
                        label="Directorio de clientes"
                        table={table}
                        filterLabels={DIRECTORY_FILTER_LABELS}
                        empty={
                            narrowed
                                ? 'Ningún cliente coincide. Revisa el formato de la cédula (V-12345678) o del teléfono (0414-1234567).'
                                : 'Todavía no tienes clientes. Las fichas nacen cuando alguien aparta desde tus catálogos.'
                        }
                        toolbar={
                            <DirectoryFilters filters={filters} search={search} onSearch={setSearch} options={options} onChange={table.setFilter} />
                        }
                    />
                </div>
            </section>
        </BackofficeLayout>
    );
}
