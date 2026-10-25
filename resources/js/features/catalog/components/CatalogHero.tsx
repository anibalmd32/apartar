import { Icon } from '@/components/ui/icon';
import { type CatalogView } from '@/types/catalog-view';
import { type CurrencyPageProps } from '@/types/exchange';
import { cilCalendar, cilLibrary } from '@coreui/icons';
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

function closingDate(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('es-VE', { day: 'numeric', month: 'long', timeZone }).format(new Date(iso));
}

interface CatalogHeroProps {
    catalog: CatalogView;
    stats?: { readonly products: number; readonly followers: number };
    action: ReactNode;
}

export function CatalogHero({ catalog, stats, action }: CatalogHeroProps) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;

    return (
        <section className="flex flex-col gap-5 rounded-3xl bg-[#e9e4fb] p-5 lg:flex-row lg:items-center lg:gap-5 lg:px-9 lg:py-8 dark:bg-[#2d273a]">
            <div className="flex min-w-0 flex-1 flex-col items-start gap-2">
                <span className="bg-primary-soft text-primary-ink inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold">
                    <Icon icon={cilLibrary} size={12} />
                    Catálogo
                </span>

                <h1 className="text-[30px] leading-[1.4] font-extrabold tracking-[-1px] lg:text-[40px] lg:tracking-[-1.5px]">{catalog.name}</h1>

                {catalog.description !== null ? <p className="text-ink-2 text-[15px] leading-normal">{catalog.description}</p> : null}

                {stats !== undefined ? (
                    <p className="text-ink-2 text-[13px] font-semibold">
                        {stats.products} {stats.products === 1 ? 'producto' : 'productos'}
                        {stats.followers > 0 ? ` · ${stats.followers} ${stats.followers === 1 ? 'persona lo sigue' : 'personas lo siguen'}` : ''}
                    </p>
                ) : null}

                {catalog.expiresAt !== null ? (
                    <p className="text-ink-2 inline-flex items-center gap-1.5 text-[13px]">
                        <Icon icon={cilCalendar} size={14} />
                        Disponible hasta el {closingDate(catalog.expiresAt, currency.timezone)}
                    </p>
                ) : null}
            </div>

            <div className="shrink-0 lg:[&_.btn]:self-end lg:[&_div]:items-end lg:[&_p]:text-right">{action}</div>
        </section>
    );
}
