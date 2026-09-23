import { LiveRateStamp } from '@/features/catalog/components/LiveRateStamp';
import { PlatformBadge } from '@/features/catalog/components/PlatformBadge';
import { CurrencyToggle } from '@/features/currency/components/CurrencyToggle';
import { type OrganizationBrand } from '@/types/backoffice';
import { type CurrencyPageProps } from '@/types/exchange';
import { Head, usePage } from '@inertiajs/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { type CSSProperties, type ReactNode, useState } from 'react';

/**
 * El marco de la cara pública: lo que ve quien abre el enlace de un catálogo
 * (`REQ-CAT-021` RB-3, RB-9).
 *
 * Todo lo que hay aquí es **del negocio del catálogo**, no de quien mira: la
 * identidad, la tasa y la hora del sello llegan en las props resueltas desde el
 * catálogo de la URL (`ResolveCatalogTenant`). No hay sesión, ni menú, ni nada
 * del backoffice.
 *
 * ## La marca se aplica con `branding`; el nombre, siempre
 *
 * El logo y el color se guardan en cualquier plan y **se aplican** solo con la
 * función `branding` (`REQ-ORG-006` RB-5, `REQ-SUB-004` RB-3). En Free se ve el
 * nombre del negocio y, al pie, el sello de la plataforma; con `branding`, el
 * logo y el color, y sin sello. Las dos cosas salen del mismo plan en el
 * servidor, así que no pueden coincidir.
 *
 * ## El refresco de la tasa vive aquí
 *
 * El cliente de react-query se crea con el marco y solo en esta superficie:
 * es la única que hoy sondea algo, y el backoffice no tiene precios que se
 * queden viejos con la pestaña abierta. Nace y muere con la página, a
 * propósito: cada navegación trae su tasa inicial del servidor, y una caché
 * que sobreviviera a la navegación podría ofrecer como «nueva» la tasa que
 * ya calculó los precios de la página siguiente.
 */

interface CatalogLayoutProps {
    title: string;
    business: OrganizationBrand;
    showsPlatformBadge: boolean;
    children: ReactNode;
}

export default function CatalogLayout({ title, business, showsPlatformBadge, children }: CatalogLayoutProps) {
    const { currency } = usePage<{ currency: CurrencyPageProps }>().props;
    const [queryClient] = useState(() => new QueryClient());

    const branded = business.brandingEnabled;
    const accent = branded && business.brandColor !== null ? ({ borderTopColor: business.brandColor } as CSSProperties) : undefined;

    return (
        <QueryClientProvider client={queryClient}>
            <div className="bg-base-200 min-h-screen">
                <Head title={title} />

                <header className="bg-base-100 border-base-300 border-t-4 border-b" style={accent}>
                    <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <div className="flex items-center gap-3">
                            {branded && business.logoUrl !== null ? (
                                <img src={business.logoUrl} alt={business.name} className="h-10 w-10 rounded object-contain" />
                            ) : null}
                            <span className="text-lg font-semibold">{business.name}</span>
                        </div>

                        <div className="flex flex-col items-end gap-1">
                            <CurrencyToggle />
                            <LiveRateStamp rate={currency.rate} timezone={currency.timezone} />
                        </div>
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-4 py-6">{children}</main>

                {showsPlatformBadge ? (
                    <footer className="flex justify-center py-6">
                        <PlatformBadge />
                    </footer>
                ) : null}
            </div>
        </QueryClientProvider>
    );
}
