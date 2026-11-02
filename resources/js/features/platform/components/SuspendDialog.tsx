import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { pf } from '@/lib/surfaces';

interface SuspendDialogProps {
    open: boolean;
    onClose: () => void;
    slug: string;
    name: string;
}

export function SuspendDialog({ open, onClose, slug, name }: SuspendDialogProps) {
    return (
        <ConfirmDialog
            open={open}
            onClose={onClose}
            title={`Suspender ${name}`}
            description="Sus catálogos se despublican y su equipo solo podrá cobrar y aprobar comprobantes hasta que pague. Sus clientes no pierden nada."
            confirmLabel="Suspender"
            method="post"
            url={pf(`/organizaciones/${slug}/pausar`)}
        >
            <p className="text-ink-2 text-sm">Queda en la auditoría con tu nombre.</p>
        </ConfirmDialog>
    );
}
