import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { formatDate } from '@/lib/dates';
import { type CollectionDelivery } from '@/types/collections';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export function DeliveryCell({ delivery, code }: { delivery: CollectionDelivery; code: string }) {
    const [asking, setAsking] = useState(false);
    const [sending, setSending] = useState(false);

    if (delivery.deliveredAt !== null) {
        return <Badge tone="success">Entregado el {formatDate(delivery.deliveredAt)}</Badge>;
    }

    if (delivery.url === null) {
        return <span className="text-ink-3 text-xs">Sin entregar</span>;
    }

    const url = delivery.url;
    const deliver = () => {
        setSending(true);
        router.post(
            url,
            { volver: true },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setAsking(false);
                },
            },
        );
    };

    return (
        <>
            <button type="button" className="btn btn-secondary btn-sm whitespace-nowrap" onClick={() => setAsking(true)}>
                Marcar entregado
            </button>
            <Modal
                open={asking}
                onClose={() => (sending ? undefined : setAsking(false))}
                title={`¿Marcar ${code} como entregado?`}
                description="Queda registrada la fecha de hoy y se le avisa al cliente. No se puede deshacer."
                actions={
                    <>
                        <Button type="button" variant="secondary" onClick={() => setAsking(false)} disabled={sending}>
                            Cancelar
                        </Button>
                        <Button type="button" onClick={deliver} loading={sending}>
                            Marcar entregado
                        </Button>
                    </>
                }
            >
                <p className="text-ink-2 text-sm">Úsalo cuando el producto ya está en manos del cliente, aunque todavía deba cuotas.</p>
            </Modal>
        </>
    );
}
