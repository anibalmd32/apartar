import { IconChip } from '@/components/ui/icon-chip';
import { cilChatBubble } from '@coreui/icons';

export function EmptyInbox({ unreadOnly, searching }: { unreadOnly: boolean; searching: boolean }) {
    if (unreadOnly) {
        return <p className="text-ink-3 px-4 py-6 text-center text-sm">No tienes mensajes sin leer.</p>;
    }

    if (searching) {
        return <p className="text-ink-3 px-4 py-6 text-center text-sm">Nada coincide con esa búsqueda.</p>;
    }

    return (
        <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
            <IconChip icon={cilChatBubble} size={48} />
            <p className="font-bold">Todavía no hay conversaciones</p>
            <p className="text-ink-2 max-w-xs text-sm">
                Una conversación empieza cuando un cliente te escribe desde tu catálogo o desde su portal. Aquí verás cada mensaje y podrás
                responderle.
            </p>
        </div>
    );
}
