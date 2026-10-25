import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { type BackofficeLayawayShowProps } from '@/types/layaway-backoffice';
import { cilChatBubble, cilEnvelopeClosed, cilPhone, cilUser } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export function LayawayCustomerCard({ customer }: { customer: NonNullable<BackofficeLayawayShowProps['customer']> }) {
    return (
        <section aria-label="Cliente" className="bg-base-100 border-line shadow-card flex flex-col gap-3.5 rounded-2xl border p-5">
            <div className="flex items-center gap-3">
                <Avatar name={customer.fullName} size={44} tone="accent" />
                <p className="min-w-0 truncate text-[15px] font-bold">{customer.fullName}</p>
            </div>
            <p className="text-ink-2 flex items-center gap-2.5 text-[13px]">
                <Icon icon={cilUser} className="text-ink-3" />
                {formatDocumentNumber(customer.documentNumber)}
            </p>
            <p className="text-ink-2 flex items-center gap-2.5 text-[13px]">
                <Icon icon={cilPhone} className="text-ink-3" />
                {formatPhone(customer.phone)}
            </p>
            {customer.email ? (
                <p className="text-ink-2 flex items-center gap-2.5 truncate text-[13px]">
                    <Icon icon={cilEnvelopeClosed} className="text-ink-3" />
                    {customer.email}
                </p>
            ) : null}
            {customer.url || customer.chatUrl ? (
                <div className="flex gap-2 [&>*]:flex-1">
                    {customer.url ? (
                        <Link href={customer.url} className="btn btn-sm">
                            Ver perfil
                        </Link>
                    ) : null}
                    {customer.chatUrl ? (
                        <Link href={customer.chatUrl} className="btn btn-secondary btn-sm">
                            <Icon icon={cilChatBubble} size={14} />
                            Abrir chat
                        </Link>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
