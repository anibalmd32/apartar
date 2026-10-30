import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { DIRECTORY_FILTER_LABELS, DirectoryFilters } from '@/features/customers/components/DirectoryFilters';
import { NewCustomerDialog } from '@/features/customers/components/NewCustomerDialog';
import { directoryColumns } from '@/features/customers/components/directoryColumns';
import { DataTable } from '@/features/data-table/components/DataTable';
import { useDebouncedFilter } from '@/features/data-table/hooks/useDebouncedFilter';
import { useServerTable } from '@/features/data-table/hooks/useServerTable';
import { ExportButton } from '@/features/exports/components/ExportButton';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CustomerDirectoryFilters, type CustomerIndexProps } from '@/types/customers';
import { cilPeople, cilPlus } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export default function CustomerIndex({ rows, meta, options, filterCounts }: CustomerIndexProps) {
    const table = useServerTable({ rows, meta, columns: directoryColumns });
    const filters = meta.filters as unknown as CustomerDirectoryFilters;
    const [search, setSearch] = useDebouncedFilter(filters.q, (value) => table.setFilter('q', value));
    const narrowed = Object.values(meta.filters).some((value) => value !== null);
    const [adding, setAdding] = useState(false);

    return (
        <BackofficeLayout
            title="Clientes"
            description="Llegan solos cuando alguien aparta desde tu catálogo, y también puedes agregarlos tú."
            actions={
                <>
                    <ExportButton type="customers" filters={meta.filters} />
                    <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}>
                        <Icon icon={cilPlus} />
                        Nuevo cliente
                    </button>
                </>
            }
        >
            <DataTable
                label="Directorio de clientes"
                table={table}
                filterLabels={DIRECTORY_FILTER_LABELS}
                empty={
                    narrowed ? (
                        'Ningún cliente coincide. Revisa el formato de la cédula (V-12345678) o del teléfono (0414-1234567).'
                    ) : (
                        <EmptyState
                            icon={cilPeople}
                            title="Aún no tienes clientes"
                            action={
                                <div className="flex flex-wrap justify-center gap-2">
                                    <button type="button" className="btn btn-primary" onClick={() => setAdding(true)}>
                                        Agregar un cliente
                                    </button>
                                    <Link className="btn" href="/backoffice/catalogos">
                                        Ver mis catálogos
                                    </Link>
                                </div>
                            }
                        >
                            Cada persona que aparta desde tu catálogo aparece aquí con su teléfono y sus apartados. También puedes agregar tú a quien
                            te compra por fuera.
                        </EmptyState>
                    )
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
            <NewCustomerDialog open={adding} onClose={() => setAdding(false)} />
        </BackofficeLayout>
    );
}
