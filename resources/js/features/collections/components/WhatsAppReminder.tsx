import { reminderUrl } from '@/features/dashboard/board/reminder';
import { cn } from '@/lib/utils';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type CollectionCustomer } from '@/types/collections';
import { usePage } from '@inertiajs/react';

interface WhatsAppReminderProps {
    customer: CollectionCustomer | null;
    dueCents: number;
    daysLate: number;
    className?: string;
}

export function WhatsAppReminder({ customer, dueCents, daysLate, className }: WhatsAppReminderProps) {
    const { teamMember } = usePage<BackofficeSharedData>().props;

    if (customer === null) {
        return null;
    }

    const url = reminderUrl({
        phone: customer.phone,
        name: customer.name,
        dueCents,
        daysLate,
        business: teamMember?.organization?.name ?? '',
        portalUrl: route('portal.access'),
    });

    if (url === null) {
        return null;
    }

    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className={cn('btn btn-sm touch:min-h-11 whitespace-nowrap', className)}
            aria-label={`Recordarle a ${customer.name} por WhatsApp`}
        >
            WhatsApp
        </a>
    );
}
