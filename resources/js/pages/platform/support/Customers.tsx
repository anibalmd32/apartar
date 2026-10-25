import PlatformLayout from '@/layouts/platform-layout';
import { Link } from '@inertiajs/react';

interface CustomerRow {
    fullName: string;
    documentNumber: string;
    phone: string;
    email: string | null;
    createdAt: string | null;
}

export default function SupportCustomers({
    organization,
    customers,
}: {
    organization: { id: number; name: string };
    customers: readonly CustomerRow[];
}) {
    return (
        <PlatformLayout title={`Clientes de ${organization.name}`} description="Acceso de soporte · solo lectura. Este acceso queda registrado.">
            <Link href="/plataforma/organizaciones" className="text-ink-2 hover:text-base-content self-start text-[13px]">
                ← Organizaciones
            </Link>
            <div className="table-frame">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Documento</th>
                            <th>Teléfono</th>
                            <th>Correo</th>
                        </tr>
                    </thead>
                    <tbody>
                        {customers.map((row) => (
                            <tr key={`${row.documentNumber}:${row.phone}`}>
                                <td>{row.fullName}</td>
                                <td>{row.documentNumber}</td>
                                <td>{row.phone}</td>
                                <td>{row.email ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </PlatformLayout>
    );
}
