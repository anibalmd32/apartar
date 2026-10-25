import { DIRECTORY_FILTER_LABELS, DirectoryFilters } from '@/features/customers/components/DirectoryFilters';
import { directoryColumns } from '@/features/customers/components/directoryColumns';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useDebouncedFilter } from '@/features/data-table/hooks/useDebouncedFilter';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CustomerDirectoryFilters, type CustomerIndexProps } from '@/types/customers';

export default function CustomerIndex({ rows, meta, options, filterCounts }: CustomerIndexProps) {
    const table = useServerTable({ rows, meta, columns: directoryColumns });
    const filters = meta.filters as unknown as CustomerDirectoryFilters;
    const [search, setSearch] = useDebouncedFilter(filters.q, (value) => table.setFilter('q', value));
    const narrowed = Object.values(meta.filters).some((value) => value !== null);

    return (
        <BackofficeLayout
            title="Clientes"
            description="Se crean solos cuando alguien aparta desde tu catálogo."
            actions={<ExportButton type="customers" filters={meta.filters} />}
        >
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
                    <DirectoryFilters
                        filters={filters}
                        search={search}
                        onSearch={setSearch}
                        options={options}
                        onChange={table.setFilter}
                        onChangeMany={table.setFilters}
                        counts={filterCounts}
                    />
                }
            />
        </BackofficeLayout>
    );
}
