import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilChatBubble, cilCheckAlt, cilShare } from '@coreui/icons';
import { useEffect, useState } from 'react';

const COPIED_FOR = 2000;

export function shareText(name: string, url: string): string {
    return `${name}\n${url}`;
}

export function whatsappUrl(name: string, url: string): string {
    return `https://wa.me/?text=${encodeURIComponent(shareText(name, url))}`;
}

interface ShareProps {
    url: string;
    name: string;
    className?: string;
}

export function ShareButton({ url, name, className }: ShareProps) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), COPIED_FOR);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const share = async () => {
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({ title: name, url });
            } catch {}

            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <button
            type="button"
            className={cn('btn btn-secondary btn-sm', className)}
            onClick={() => void share()}
            aria-label={copied ? 'Enlace copiado' : `Compartir ${name}`}
        >
            <Icon icon={copied ? cilCheckAlt : cilShare} size={14} />
            {copied ? 'Copiado' : 'Compartir'}
        </button>
    );
}

export function WhatsAppButton({ url, name, className }: ShareProps) {
    return (
        <a href={whatsappUrl(name, url)} target="_blank" rel="noopener noreferrer" className={cn('btn btn-sm', className)}>
            <Icon icon={cilChatBubble} size={14} />
            WhatsApp
        </a>
    );
}

export function CatalogShareRow({ url, name }: ShareProps) {
    return (
        <div className="grid grid-cols-2 gap-2">
            <WhatsAppButton url={url} name={name} />
            <ShareButton url={url} name={name} />
        </div>
    );
}
