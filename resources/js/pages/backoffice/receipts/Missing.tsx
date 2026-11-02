import { EmptyState } from '@/components/ui/empty-state';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { cilFile } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export default function ReceiptMissing({ inboxUrl }: { inboxUrl: string }) {
    return (
        <BackofficeLayout title="Comprobante no encontrado" back={{ href: inboxUrl, label: 'Comprobantes' }}>
            <section className="bg-base-100 border-line shadow-card rounded-2xl border px-5 py-10">
                <EmptyState
                    icon={cilFile}
                    title="Este comprobante no está en tu negocio"
                    action={
                        <Link href={inboxUrl} className="btn btn-primary">
                            Ver los comprobantes
                        </Link>
                    }
                >
                    Puede que el enlace sea de otro negocio o que el número esté mal escrito. Los comprobantes que te tocan revisar están en la
                    bandeja.
                </EmptyState>
            </section>
        </BackofficeLayout>
    );
}
