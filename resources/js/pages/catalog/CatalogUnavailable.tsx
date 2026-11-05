import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Icon } from '@/components/ui/icon';
import { storeStyle } from '@/lib/store-brand';
import { cilLibrary, cilUser } from '@coreui/icons';
import { Head } from '@inertiajs/react';

export default function CatalogUnavailable() {
    return (
        <div data-store-page className="vitrina bg-base-200 flex min-h-screen flex-col" style={storeStyle(null)}>
            <Head title="Catálogo no disponible" />

            <header className="bg-base-100 border-line border-b">
                <div className="mx-auto flex max-w-[1440px] items-center justify-end gap-1.5 px-4 py-3 sm:gap-3 lg:min-h-[76px] lg:px-20">
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
                        <Icon icon={cilLibrary} size={48} />
                    </span>
                    <h1 className="tracking-heading text-[26px] leading-[1.3] font-extrabold">Este catálogo no está disponible</h1>
                    <p className="text-ink-2 text-[15px] leading-relaxed">
                        Puede que el negocio lo haya retirado por ahora o que el enlace haya cambiado. Pídele el enlace nuevo a quien te lo compartió.
                    </p>

                    <div className="bg-base-100 border-line shadow-card mt-4 flex w-full flex-col items-center gap-3 rounded-2xl border p-6">
                        <p className="text-sm font-bold">¿Ya apartaste aquí? Tu apartado sigue vigente.</p>
                        <p className="text-ink-2 text-[13px]">
                            Puedes ver tus cuotas, subir comprobantes y escribirle al negocio desde Mis apartados.
                        </p>
                        <div className="flex flex-wrap justify-center gap-2">
                            <a href="/mi-cuenta" className="btn btn-primary">
                                Ver mis apartados
                            </a>
                            <a href="/mi-cuenta/acceso" className="btn">
                                Entrar con mi código
                            </a>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}
