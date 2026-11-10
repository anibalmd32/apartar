import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { type HeldCopy } from '@/types/product';

interface PhotoConcernDialogProps {
    held: HeldCopy;
    confirming: boolean;
    onConfirm: () => void;
    onCancel: () => void;
}

export function PhotoConcernDialog({ held, confirming, onConfirm, onCancel }: PhotoConcernDialogProps) {
    return (
        <Modal
            open
            onClose={onCancel}
            title="¿Esto es lo que vas a vender?"
            description={`Las fotos parecen mostrar ${held.label}, no un producto.`}
            actions={
                <>
                    <Button variant="secondary" onClick={onCancel} disabled={confirming}>
                        Cancelar
                    </Button>
                    <Button onClick={onConfirm} loading={confirming}>
                        Sí, lo voy a vender
                    </Button>
                </>
            }
        >
            {held.observation !== '' ? <p className="text-ink-2 text-sm">La IA vio: {held.observation}</p> : null}
            <p className="text-ink-2 text-sm">
                Si de verdad lo vas a vender, confirma y te mostramos la sugerencia. El equipo de Apartar revisará las fotos y podrás publicar el
                producto cuando las apruebe.
            </p>
        </Modal>
    );
}
