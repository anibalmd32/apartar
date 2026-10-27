import { Alert } from '@/components/ui/alert';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';

interface ReactivateDialogProps {
    open: boolean;
    onClose: () => void;
    slug: string;
    name: string;
    hasOverdueInvoice: boolean;
}

export function ReactivateDialog({ open, onClose, slug, name, hasOverdueInvoice }: ReactivateDialogProps) {
    return (
        <ConfirmDialog
            open={open}
            onClose={onClose}
            title={`Reactivar ${name}`}
            description="Su equipo vuelve a crear, editar y publicar. Los catálogos que se despublicaron no vuelven solos: los publica el dueño."
            confirmLabel="Reactivar"
            tone="primary"
            method="post"
            url={`/plataforma/organizaciones/${slug}/reactivar`}
        >
            {hasOverdueInvoice ? (
                <Alert tone="warning" title="Tiene una factura vencida">
                    El cobro automático la volverá a suspender en su próxima pasada. Para evitarlo, concede una cortesía o anula la factura.
                </Alert>
            ) : null}
            <p className="text-ink-2 text-sm">Queda en la auditoría con tu nombre.</p>
        </ConfirmDialog>
    );
}
