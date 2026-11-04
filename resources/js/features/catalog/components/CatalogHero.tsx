import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type CatalogView } from '@/types/catalog-view';
import { type CurrencyPageProps } from '@/types/exchange';
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

interface CatalogHeroProps {
    catalog: CatalogView;
    stats?: { readonly products: number; readonly followers: number };
    action: ReactNode;
}

export function CatalogHero({ catalog, stats, action }: CatalogHeroProps) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;
    const cover = catalog.cover;

    const meta = [
        stats !== undefined ? `${stats.products} ${stats.products === 1 ? 'producto' : 'productos'}` : null,
        catalog.expiresAt !== null ? `Disponible hasta el ${formatDate(catalog.expiresAt, 'long', currency.timezone)}` : null,
    ].filter((item): item is string => item !== null);

    return (
        <section
            className={cn(
                'relative isolate flex flex-col gap-3 overflow-hidden lg:flex-row lg:items-end lg:gap-6',
                cover === null ? 'py-1 lg:py-2' : 'bg-neutral min-h-[168px] justify-end rounded-2xl p-5 text-white lg:min-h-[300px] lg:p-9',
            )}
        >
            {cover !== null ? (
                <>
                    <img
                        src={cover.card}
                        srcSet={`${cover.card} 1x, ${cover.zoom} 2x`}
                        alt=""
                        className="absolute inset-0 -z-20 h-full w-full object-cover"
                    />
                    <span aria-hidden="true" data-scrim className="absolute inset-0 -z-10 bg-gradient-to-t from-black/85 via-black/45 to-black/10" />
                </>
            ) : null}

            <div className="flex min-w-0 flex-1 flex-col items-start gap-1.5">
                <h1 className="text-[1.875rem] leading-[1.1] font-extrabold [font-stretch:88%] lg:text-[2.75rem]">{catalog.name}</h1>

                {catalog.description !== null ? (
                    <p
                        className={cn(
                            'line-clamp-2 max-w-[62ch] text-[0.9375rem] leading-normal lg:line-clamp-none',
                            cover === null ? 'text-ink-2' : 'text-white/90',
                        )}
                    >
                        {catalog.description}
                    </p>
                ) : null}

                {meta.length > 0 ? (
                    <p className={cn('text-[0.8125rem] font-semibold', cover === null ? 'text-ink-3' : 'text-white/85')}>{meta.join(' · ')}</p>
                ) : null}
            </div>

            <div className={cn('shrink-0', cover !== null && '[&_p]:text-white/80')}>{action}</div>
        </section>
    );
}
