import { BackLink } from '@/components/ui/back-link';
import PlatformLayout from '@/layouts/platform-layout';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { pf } from '@/lib/surfaces';

interface CustomerRow {
    fullName: string;
    documentNumber: string | null;
    phone: string | null;
    email: string | null;
    createdAt: string | null;
}

export default function SupportCustomers({
    organization,
    customers,
}: {
    organization: { id: number; name: string; slug: string };
    customers: readonly CustomerRow[];
}) {
    return (
        <PlatformLayout title={`Clientes de ${organization.name}`} description="Acceso de soporte · solo lectura. Este acceso queda registrado.">
            <BackLink href={pf(`/organizaciones/${organization.slug}`)}>{organization.name}</BackLink>
            <div className="table-frame table-stack md:overflow-x-auto">
                <table className="table">
                    <thead>
                        <tr>
                            <th scope="col">Nombre</th>
                            <th scope="col">Documento</th>
                            <th scope="col">Teléfono</th>
                            <th scope="col">Correo</th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.map((row, index) => (
                            <tr key={`${row.documentNumber ?? ''}:${row.phone ?? ''}:${index}`}>
                                <td data-cell="primary" className="font-medium">
                                    {row.fullName}
                                </td>
                                <td data-label="Documento" className="whitespace-nowrap">
                                    {formatDocumentNumber(row.documentNumber)}
                                </td>
                                <td data-label="Teléfono" className="whitespace-nowrap">
                                    {formatPhone(row.phone)}
                                </td>
                                <td data-label="Correo" className="min-w-0 break-all">
                                    {row.email}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </PlatformLayout>
    );
}
