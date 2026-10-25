import { Icon } from '@/components/ui/icon';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { cilCheckAlt, cilCopy } from '@coreui/icons';
import { useEffect, useState } from 'react';

const COPIED_FOR = 2000;

interface CopyButtonProps {
    value: string;
    label: string;
    className?: string;
    size?: number;
}

export function CopyButton({ value, label, className, size = 14 }: CopyButtonProps) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), COPIED_FOR);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            setCopied(false);
            toast.error(`No se pudo copiar. Mantén pulsado el texto para copiar ${label} a mano.`);
        }
    };

    return (
        <button
            type="button"
            onClick={() => void copy()}
            className={cn(
                'hit-area text-ink-3 hover:text-base-content hover:bg-base-200 shrink-0 cursor-pointer rounded-md p-1.5',
                copied && 'text-success',
                className,
            )}
            aria-label={copied ? 'Copiado' : `Copiar ${label}`}
            title={copied ? 'Copiado' : 'Copiar'}
        >
            <Icon icon={copied ? cilCheckAlt : cilCopy} size={size} />
        </button>
    );
}
