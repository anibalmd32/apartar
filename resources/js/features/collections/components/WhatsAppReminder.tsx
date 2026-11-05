import { Icon } from '@/components/ui/icon';
import { reminderUrl } from '@/features/dashboard/board/reminder';
import { cn } from '@/lib/utils';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type CollectionCustomer } from '@/types/collections';
import { cibWhatsapp } from '@coreui/icons';
import { usePage } from '@inertiajs/react';

interface WhatsAppReminderProps {
    customer: CollectionCustomer | null;
    dueCents: number;
    daysLate: number;
    className?: string;
    iconOnly?: boolean;
}

export function WhatsAppReminder({ customer, dueCents, daysLate, className, iconOnly = false }: WhatsAppReminderProps) {
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
            className={cn('btn btn-sm touch:min-h-11 whitespace-nowrap', iconOnly && 'btn-square size-11', className)}
            aria-label={`Recordarle a ${customer.name} por WhatsApp`}
        >
            {iconOnly ? <Icon icon={cibWhatsapp} size={18} /> : 'WhatsApp'}
        </a>
    );
}
