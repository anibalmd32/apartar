import { Brand } from '@/components/shell/brand';
import { Icon } from '@/components/ui/icon';
import { cilLibrary, cilUser } from '@coreui/icons';
import { Head } from '@inertiajs/react';

export default function CatalogUnavailable() {
    return (
        <div className="bg-base-200 flex min-h-screen flex-col">
            <Head title="Catálogo no disponible" />

            <header className="bg-base-100 border-line border-b">
                <div className="mx-auto flex max-w-[1440px] items-center justify-between gap-3 px-4 py-3 lg:min-h-[72px] lg:px-20">
                    <Brand />
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
                        <Icon icon={cilLibrary} size={48} />
                    </span>
                    <h1 className="text-[26px] leading-[1.3] font-extrabold tracking-[-0.4px]">Este catálogo no está disponible</h1>
                    <p className="text-ink-2 text-[15px] leading-relaxed">
                        Puede que el negocio lo haya retirado por ahora o que el enlace haya cambiado. Pídele el enlace nuevo a quien te lo compartió.
                    </p>

                    <div className="bg-base-100 border-line shadow-card mt-4 flex w-full flex-col items-center gap-3 rounded-2xl border p-6">
                        <p className="text-sm font-bold">¿Ya apartaste aquí? Tu apartado sigue vigente.</p>
                        <p className="text-ink-2 text-[13px]">Puedes ver tus cuotas, subir comprobantes y escribirle al negocio desde Mi cuenta.</p>
                        <div className="flex flex-wrap justify-center gap-2">
                            <a href="/mi-cuenta" className="btn btn-primary">
                                Ir a Mi cuenta
                            </a>
                            <a href="/mi-cuenta/acceso" className="btn">
                                Entrar con mi código
                            </a>
                        </div>
                    </div>
                </div>
            </main>

            <footer className="flex items-center justify-center gap-1.5 px-4 pt-6 pb-8">
                <span className="text-ink-3 text-xs">Hecho con</span>
                <span className="text-ink-2 text-[13px] font-extrabold tracking-[-0.2px]">apartar</span>
            </footer>
        </div>
    );
}
