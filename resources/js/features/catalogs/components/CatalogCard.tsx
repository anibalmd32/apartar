import { Icon } from '@/components/ui/icon';
import { ProductPlaceholder } from '@/features/catalog/components/ProductPlaceholder';
import { ShareButton } from '@/features/catalogs/components/CatalogShareButtons';
import { CatalogVisibilityBadge } from '@/features/catalogs/components/CatalogVisibilityBadge';
import { type CatalogListItem } from '@/types/catalog';
import { cilBasket, cilCalendar, cilCheckAlt, cilCopy, cilExternalLink, cilUserFollow } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const COPIED_FOR = 2000;

function day(iso: string): string {
    return new Date(iso).toLocaleDateString('es-VE', { day: '2-digit', month: '2-digit' });
}

function expiry(catalog: CatalogListItem): string {
    if (catalog.expiresAt === null) {
        return catalog.visibility === 'draft' ? 'Sin publicar' : 'Sin vencimiento';
    }

    return `${catalog.visibility === 'expired' ? 'Venció' : 'Vence'} ${day(catalog.expiresAt)}`;
}

export function CatalogCard({ catalog }: { catalog: CatalogListItem }) {
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
            await navigator.clipboard.writeText(catalog.shareUrl);
            setCopied(true);
        } catch {
            setCopied(false);
        }
    };

    const selling = catalog.visibility === 'listed' || catalog.visibility === 'unlisted';
    const editUrl = `/backoffice/catalogos/${catalog.id}/editar`;

    return (
        <article className="bg-base-100 border-line shadow-card flex h-full flex-col overflow-hidden rounded-2xl border">
            <div className="relative h-[130px] shrink-0 overflow-hidden">
                {catalog.coverUrl ? (
                    <img src={catalog.coverUrl} alt="" className="h-full w-full object-cover" />
                ) : catalog.thumbs !== undefined && catalog.thumbs.length > 0 ? (
                    <div className="grid h-full gap-0.5" style={{ gridTemplateColumns: `repeat(${catalog.thumbs.length}, minmax(0, 1fr))` }}>
                        {catalog.thumbs.map((thumb) => (
                            <img key={thumb} src={thumb} alt="" loading="lazy" className="h-full w-full object-cover" />
                        ))}
                    </div>
                ) : (
                    <ProductPlaceholder name={catalog.name} className="text-4xl" />
                )}
                <CatalogVisibilityBadge visibility={catalog.visibility} className="bg-base-100 absolute top-2.5 right-2.5" />
            </div>

            <div className="flex flex-1 flex-col gap-2.5 p-4">
                <h2 className="text-[17px] leading-tight font-bold">
                    <Link href={editUrl} className="hover:underline">
                        {catalog.name}
                    </Link>
                </h2>

                <p className="text-ink-3 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                    <span className="inline-flex items-center gap-1">
                        <Icon icon={cilBasket} size={12} />
                        {catalog.productsCount === 1 ? '1 producto' : `${catalog.productsCount} productos`}
                    </span>
                    {catalog.subscribersCount !== undefined ? (
                        <span className="inline-flex items-center gap-1">
                            <Icon icon={cilUserFollow} size={12} />
                            {catalog.subscribersCount === 1 ? '1 suscriptor' : `${catalog.subscribersCount} suscriptores`}
                        </span>
                    ) : null}
                    <span className="inline-flex items-center gap-1">
                        <Icon icon={cilCalendar} size={12} />
                        {expiry(catalog)}
                    </span>
                </p>

                <div className="bg-surface-2 flex items-center gap-2 rounded-lg py-1.5 pr-1.5 pl-3">
                    <span className="text-ink-2 min-w-0 flex-1 text-xs break-all select-all">{catalog.shareUrl}</span>
                    <button
                        type="button"
                        onClick={() => void copy()}
                        className="text-ink-3 hover:text-base-content hover:bg-base-100 shrink-0 cursor-pointer rounded-md p-1.5"
                        aria-label={copied ? 'Enlace copiado' : `Copiar el enlace de ${catalog.name}`}
                    >
                        <Icon icon={copied ? cilCheckAlt : cilCopy} size={14} />
                    </button>
                </div>

                {catalog.updatedAt !== null ? (
                    <p className="text-ink-3 text-xs">
                        Modificado {catalog.updatedAt}
                        {catalog.updatedBy !== null ? ` · ${catalog.updatedBy}` : null}
                    </p>
                ) : null}

                <div className="mt-auto flex gap-2 pt-1">
                    <Link href={editUrl} className="btn btn-sm flex-1" aria-label={`Editar ${catalog.name}`}>
                        Editar
                    </Link>
                    {selling ? (
                        <>
                            <ShareButton url={catalog.shareUrl} name={catalog.name} className="flex-1" />
                            <a
                                href={catalog.shareUrl}
                                target="_blank"
                                rel="noreferrer"
                                className="btn btn-ghost btn-sm btn-square"
                                aria-label={`Abrir ${catalog.name}`}
                                title="Abrir"
                            >
                                <Icon icon={cilExternalLink} size={14} />
                            </a>
                        </>
                    ) : null}
                </div>
            </div>
        </article>
    );
}
