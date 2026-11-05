import { Avatar } from '@/components/shell/avatar';
import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Icon } from '@/components/ui/icon';
import { storeStyle } from '@/lib/store-brand';
import { type CatalogExpiredProps } from '@/types/catalog-view';
import { cilArrowRight, cilCalendar, cilUser } from '@coreui/icons';
import { Head } from '@inertiajs/react';

export default function CatalogExpired({ catalogName, businessName, storefrontUrl, endedOn, withdrawn = false }: CatalogExpiredProps) {
    return (
        <div data-store-page className="vitrina bg-base-200 flex min-h-screen flex-col" style={storeStyle(null)}>
            <Head title={withdrawn ? `${catalogName} no está disponible` : `${catalogName} terminó`} />

            <header className="bg-base-100 border-line border-b">
                <div className="mx-auto flex max-w-[1440px] items-center gap-1.5 px-4 py-3 sm:gap-3 lg:min-h-[76px] lg:px-20">
                    <Avatar name={businessName} tone="neutral" className="size-10! rounded-xl! text-[15px]! sm:size-11!" />
                    <p className="min-w-0 flex-1 truncate text-[1.0625rem] leading-tight font-extrabold [font-stretch:90%] lg:text-xl">
                        {businessName}
                    </p>
                    <ThemeToggle />
                    <a href="/mi-cuenta" className="btn btn-ghost touch:min-h-11 shrink-0 gap-2 px-2.5 sm:px-3" aria-label="Mis apartados">
                        <Icon icon={cilUser} size={18} />
                        <span className="max-sm:hidden">Mis apartados</span>
                    </a>
                </div>
            </header>

            <main className="flex flex-1 items-center justify-center px-4 py-12">
                <div className="flex max-w-xl flex-col items-center gap-4 text-center">
                    <span
                        aria-hidden="true"
                        className="bg-neutral-soft text-ink-2 mb-2 inline-flex size-[120px] items-center justify-center rounded-full"
                    >
                        <Icon icon={cilCalendar} size={48} />
                    </span>
                    <h1 className="tracking-heading text-[26px] leading-[1.3] font-extrabold lg:text-[34px]">
                        {withdrawn ? 'Este catálogo ya no está disponible' : 'Esta campaña ya terminó'}
                    </h1>
                    <p className="text-ink-2 text-[15px] leading-relaxed">
                        {withdrawn ? (
                            <>
                                El enlace es correcto, pero {businessName} retiró «{catalogName}» por ahora. Si te interesaba algo, pregúntale al
                                negocio: puede que lo vuelva a publicar.
                            </>
                        ) : (
                            <>
                                El enlace es correcto: «{catalogName}» de {businessName} estuvo disponible
                                {endedOn ? ` hasta el ${endedOn.slice(8, 10)}/${endedOn.slice(5, 7)}` : ''}, pero su plazo terminó. Si te interesaba
                                algo, pregúntale al negocio: puede que tenga una campaña nueva.
                            </>
                        )}
                    </p>

                    {storefrontUrl !== null ? (
                        <a href={storefrontUrl} className="btn btn-primary btn-lg">
                            Ver los catálogos de {businessName}
                            <Icon icon={cilArrowRight} />
                        </a>
                    ) : null}

                    <a href="/mi-cuenta" className="text-primary-ink text-sm font-semibold hover:underline">
                        ¿Tienes un apartado? Míralo en Mis apartados
                    </a>
                </div>
            </main>

            <footer className="mx-auto w-full max-w-[1440px] px-4 pt-6 pb-8 lg:px-20 lg:py-7">
                <p className="text-ink-3 text-xs">Los pagos se hacen directo a {businessName}. Apartar no cobra ni recibe dinero de clientes.</p>
            </footer>
        </div>
    );
}
