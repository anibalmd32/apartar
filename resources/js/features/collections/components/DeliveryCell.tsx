import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/dates';
import { type CollectionDelivery } from '@/types/collections';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export function DeliveryCell({ delivery, code }: { delivery: CollectionDelivery; code: string }) {
    const [sending, setSending] = useState(false);

    if (delivery.deliveredAt !== null) {
        return <Badge tone="success">Entregado el {formatDate(delivery.deliveredAt)}</Badge>;
    }

    if (delivery.url === null) {
        return <span className="text-ink-3 text-xs">Sin entregar</span>;
    }

    const url = delivery.url;
    const deliver = () => {
        if (!window.confirm(`¿Marcar ${code} como entregado? No se puede deshacer.`)) {
            return;
        }

        setSending(true);
        router.post(url, { volver: true }, { preserveScroll: true, onFinish: () => setSending(false) });
    };

    return (
        <button type="button" className="btn btn-secondary btn-sm whitespace-nowrap" onClick={deliver} disabled={sending}>
            {sending ? <span className="loading loading-spinner loading-xs" aria-hidden /> : null}
            Marcar entregado
        </button>
    );
}
