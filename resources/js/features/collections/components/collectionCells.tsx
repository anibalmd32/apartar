import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { NO_PHONE } from '@/lib/identity-format';
import { type CollectionCustomer } from '@/types/collections';
import { cilChatBubble, cilCheckAlt, cilCopy } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export function CustomerCell({ customer }: { customer: CollectionCustomer | null }) {
    const [copied, setCopied] = useState(false);

    if (customer === null) {
        return <span className="text-ink-3">—</span>;
    }

    const copy = () => {
        void navigator.clipboard?.writeText(customer.phone ?? '').then(() => setCopied(true));
    };

    return (
        <div className="flex items-center gap-3">
            <Avatar name={customer.name} size={32} tone="accent" />
            <div className="flex min-w-0 flex-col">
                {customer.url ? (
                    <Link href={customer.url} className="hit-area font-semibold hover:underline">
                        {customer.name}
                    </Link>
                ) : (
                    <span className="font-semibold">{customer.name}</span>
                )}
                {customer.phone === null ? (
                    <span className="text-ink-3 text-xs">{NO_PHONE}</span>
                ) : (
                    <span className="text-ink-3 flex items-center gap-1.5 text-xs whitespace-nowrap">
                        <a href={`tel:${customer.phone}`} className="hit-area hover:text-base-content">
                            {customer.phoneLabel}
                        </a>
                        <button
                            type="button"
                            className="hit-area hover:text-base-content rounded p-0.5"
                            onClick={copy}
                            aria-label={`Copiar el teléfono de ${customer.name}`}
                            title={copied ? 'Copiado' : 'Copiar'}
                        >
                            <Icon icon={copied ? cilCheckAlt : cilCopy} size={12} />
                        </button>
                    </span>
                )}
            </div>
        </div>
    );
}

export function LayawayLink({ layaway }: { layaway: { code: string; url: string } }) {
    return (
        <Link href={layaway.url} className="hit-area text-primary-ink text-[13px] font-bold whitespace-nowrap hover:underline">
            {layaway.code}
        </Link>
    );
}

export function Contact({ customer }: { customer: CollectionCustomer | null }) {
    if (customer === null) {
        return null;
    }

    return (
        <span className="flex justify-end gap-1.5">
            {customer.chatUrl ? (
                <Link href={customer.chatUrl} className="btn btn-sm btn-square" aria-label={`Escribir a ${customer.name}`} title="Abrir el chat">
                    <Icon icon={cilChatBubble} size={14} />
                </Link>
            ) : null}
        </span>
    );
}
