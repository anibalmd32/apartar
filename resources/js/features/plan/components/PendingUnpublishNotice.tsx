import { Alert } from '@/components/ui/alert';
import { formatDate } from '@/lib/dates';

export interface PendingUnpublish {
    readonly excess: number;
    readonly limit: number;
    readonly unpublishAt: string;
}

interface PendingUnpublishNoticeProps {
    readonly pending: PendingUnpublish;
    readonly timeZone: string;
}

export function PendingUnpublishNotice({ pending, timeZone }: PendingUnpublishNoticeProps) {
    const catalogs = pending.excess === 1 ? '1 catálogo publicado' : `${pending.excess} catálogos publicados`;

    return (
        <Alert tone="warning">
            Tu plan permite {pending.limit} y tienes {catalogs} de más. A partir del {formatDate(pending.unpublishAt, 'long', timeZone)} se
            despublicarán los más recientes y se conservarán los más antiguos. Nada se borra. Si prefieres elegir tú, despublica los que sobren antes
            de esa fecha; si subes a un plan que los cubra, no se despublica ninguno.
        </Alert>
    );
}
