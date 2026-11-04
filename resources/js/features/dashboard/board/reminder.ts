import { whatsappChatUrl } from '@/features/catalog/lib/whatsapp';
import { formatUsdCents } from '@/lib/cents';

interface Reminder {
    phone: string | null;
    name: string;
    dueCents: number;
    daysLate: number;
    business: string;
    portalUrl: string;
}

export function reminderText({ name, dueCents, daysLate, business, portalUrl }: Omit<Reminder, 'phone'>): string {
    const firstName = name.trim().split(/\s+/)[0] ?? name;
    const when = daysLate > 0 ? `tienes ${formatUsdCents(dueCents)} pendientes de tu apartado` : `hoy vence tu cuota de ${formatUsdCents(dueCents)}`;

    return `Hola, ${firstName}. Te escribo de ${business}: ${when}. Puedes ver tu plan y subir el comprobante aquí: ${portalUrl}`;
}

export function reminderUrl(reminder: Reminder): string | null {
    const chat = reminder.phone === null ? null : whatsappChatUrl(reminder.phone);

    return chat === null ? null : `${chat}?text=${encodeURIComponent(reminderText(reminder))}`;
}
