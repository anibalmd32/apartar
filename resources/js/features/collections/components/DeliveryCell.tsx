import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { formatDate } from '@/lib/dates';
import { type CollectionDelivery } from '@/types/collections';
import { cilTruck } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export function DeliveryCell({ delivery, code, iconOnly = false }: { delivery: CollectionDelivery; code: string; iconOnly?: boolean }) {
    const [asking, setAsking] = useState(false);
    const [sending, setSending] = useState(false);

    if (iconOnly && (delivery.deliveredAt !== null || delivery.url === null)) {
        return null;
    }

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
            {iconOnly ? (
                <button
                    type="button"
                    className="btn btn-square size-11 min-h-11"
                    onClick={() => setAsking(true)}
                    aria-label={`Marcar ${code} como entregado`}
                >
                    <Icon icon={cilTruck} size={18} />
                </button>
            ) : (
                <button
                    type="button"
                    className="btn btn-ghost btn-sm max-md:btn-square whitespace-nowrap max-md:size-11"
                    onClick={() => setAsking(true)}
                    aria-label={`Marcar ${code} como entregado`}
                >
                    <Icon icon={cilTruck} size={16} className="md:hidden" />
                    <span className="max-md:sr-only">Marcar entregado</span>
                </button>
            )}
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
