import PlatformLayout from '@/layouts/platform-layout';
import { Link } from '@inertiajs/react';

interface LayawayRow {
    code: string;
    customer: string;
    product: string;
    status: string;
    totalCents: number;
    paidCents: number;
    remainingCents: number;
    createdAt: string | null;
}

const usd = (cents: number) => new Intl.NumberFormat('es', { style: 'currency', currency: 'USD' }).format(cents / 100);

export default function SupportLayaways({ organization, layaways }: { organization: { id: number; name: string }; layaways: readonly LayawayRow[] }) {
    return (
        <PlatformLayout title={`Apartados de ${organization.name}`} description="Acceso de soporte · solo lectura. Este acceso queda registrado.">
            <Link href="/plataforma/organizaciones" className="text-ink-2 hover:text-base-content self-start text-[13px]">
                ← Organizaciones
            </Link>
            <div className="table-frame">
                <table className="table">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Cliente</th>
                            <th>Producto</th>
                            <th>Estado</th>
                            <th className="text-right">Total</th>
                            <th className="text-right">Pagado</th>
                            <th className="text-right">Falta</th>
                        </tr>
                    </thead>
                    <tbody>
                        {layaways.map((row) => (
                            <tr key={row.code}>
                                <td>{row.code}</td>
                                <td>{row.customer}</td>
                                <td>{row.product}</td>
                                <td>{row.status}</td>
                                <td className="text-right">{usd(row.totalCents)}</td>
                                <td className="text-right">{usd(row.paidCents)}</td>
                                <td className="text-right">{usd(row.remainingCents)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </PlatformLayout>
    );
}
