import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { type CatalogVisibility } from '@/types/catalog';
import { cilCheckAlt, cilCopy } from '@coreui/icons';
import { useEffect, useState } from 'react';

interface ShareLinkFieldProps {
    url: string;
    name: string;
    visibility: CatalogVisibility;
}

const COPIED_FOR = 2000;

export function ShareLinkField({ url, name, visibility }: ShareLinkFieldProps) {
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
            await navigator.clipboard.writeText(url);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    return (
        <section className="flex flex-col gap-2.5">
            <div className="flex flex-col gap-2">
                <label className="flex flex-col gap-1">
                    <span className="sr-only">Enlace del catálogo</span>

                    <input className="input w-full text-[13px]" value={url} readOnly onFocus={(event) => event.target.select()} />
                </label>

                <Button type="button" size="sm" variant="secondary" onClick={copy}>
                    <Icon icon={copied ? cilCheckAlt : cilCopy} size={14} />
                    {copied ? 'Copiado' : 'Copiar enlace'}
                </Button>
            </div>

            <p className="text-ink-2 text-xs">
                {visibility === 'listed'
                    ? 'Aparece en tu vitrina y cualquiera con el enlace puede verlo.'
                    : 'Cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina.'}
            </p>

            <figure className="border-line bg-base-100 flex flex-col gap-1 rounded-lg border p-3">
                <figcaption className="text-ink-3 text-xs font-medium">Así se verá al compartirlo</figcaption>

                <span className="text-sm font-semibold">{name}</span>
                <span className="text-ink-3 text-xs break-all">{url}</span>
            </figure>
        </section>
    );
}
