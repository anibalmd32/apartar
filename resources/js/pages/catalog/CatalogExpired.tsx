import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { type CatalogExpiredProps } from '@/types/catalog-view';
import { cilArrowRight, cilCalendar, cilUser } from '@coreui/icons';
import { Head } from '@inertiajs/react';

export default function CatalogExpired({ catalogName, businessName, storefrontUrl, endedOn }: CatalogExpiredProps) {
    return (
        <div className="bg-base-200 flex min-h-screen flex-col">
            <Head title={`${catalogName} terminó`} />

            <header className="bg-base-100 border-line border-b">
                <div className="mx-auto flex max-w-[1440px] items-center gap-3 px-4 py-3 lg:min-h-[72px] lg:px-20">
                    <Avatar name={businessName} tone="accent" />
                    <p className="min-w-0 flex-1 truncate text-[15px] font-bold lg:text-base">{businessName}</p>
                    <a href="/mi-cuenta" className="btn lg:border-line-strong max-lg:btn-square max-lg:size-9" aria-label="Mi cuenta">
                        <Icon icon={cilUser} />
                        <span className="max-lg:hidden">Mi cuenta</span>
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
                    <h1 className="text-[26px] leading-[1.3] font-extrabold tracking-[-1px] lg:text-[34px]">Esta campaña ya terminó</h1>
                    <p className="text-ink-2 text-[15px] leading-relaxed">
                        El enlace es correcto: «{catalogName}» de {businessName} estuvo disponible
                        {endedOn ? ` hasta el ${endedOn.slice(8, 10)}/${endedOn.slice(5, 7)}` : ''}, pero su plazo terminó. Si te interesaba algo,
                        pregúntale al negocio: puede que tenga una campaña nueva.
                    </p>

                    {storefrontUrl !== null ? (
                        <a href={storefrontUrl} className="btn btn-primary btn-lg">
                            Ver los catálogos de {businessName}
                            <Icon icon={cilArrowRight} />
                        </a>
                    ) : null}

                    <a href="/mi-cuenta" className="text-primary-ink text-sm font-semibold hover:underline">
                        ¿Tienes un apartado? Entra a Mi cuenta
                    </a>
                </div>
            </main>

            <footer className="mx-auto w-full max-w-[1440px] px-4 pt-6 pb-8 lg:px-20 lg:py-7">
                <p className="text-ink-3 text-xs">Los pagos se hacen directo a {businessName}. Apartar no cobra ni recibe dinero de clientes.</p>
            </footer>
        </div>
    );
}
