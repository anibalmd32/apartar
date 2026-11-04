import { BoardBrand } from '@/features/marketing/board/BoardBrand';
import { CookiePreferencesLink } from '@/features/telemetry/components/CookieConsent';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { HOW_IT_WORKS_ANCHOR } from './SiteHeader';

const linkClass = 'text-pz-ink touch:min-h-11 flex cursor-pointer items-center text-left text-[0.9375rem] font-medium hover:underline';

function Column({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <h2 className="text-pz-ink-2 text-[0.8125rem] font-bold tracking-[0.06em] uppercase">{title}</h2>
            {children}
        </div>
    );
}

export function SiteFooter() {
    return (
        <footer>
            <div className="mx-auto grid max-w-[1440px] grid-cols-2 gap-x-6 gap-y-8 px-4 pt-9 pb-12 sm:px-6 lg:grid-cols-[1.6fr_1fr_1fr_1fr] lg:px-12 lg:pt-11 lg:pb-14">
                <div className="col-span-2 flex flex-col gap-2 lg:col-span-1">
                    <BoardBrand />
                    <p className="text-pz-ink-2 max-w-[34ch] text-[0.9375rem]">Catálogos con apartado y cuotas para negocios en Venezuela.</p>
                    <p className="text-pz-ink-3 text-[0.8125rem]">© {new Date().getFullYear()} Apartar</p>
                </div>

                <Column title="Producto">
                    <Link href={`${route('home')}#${HOW_IT_WORKS_ANCHOR}`} className={linkClass}>
                        Cómo funciona
                    </Link>
                    <Link href={route('pricing')} className={linkClass}>
                        Precios
                    </Link>
                    <Link href={route('login')} className={linkClass}>
                        Entrar a mi negocio
                    </Link>
                </Column>

                <Column title="¿Apartaste algo?">
                    <Link href={route('portal.access')} className={linkClass}>
                        Mi cuenta de cliente
                    </Link>
                </Column>

                <Column title="Legal">
                    <Link href={route('legal.terms')} className={linkClass}>
                        Términos
                    </Link>
                    <Link href={route('legal.privacy')} className={linkClass}>
                        Privacidad
                    </Link>
                    <CookiePreferencesLink className={linkClass} />
                </Column>
            </div>
        </footer>
    );
}
