import { Brand } from '@/components/shell/brand';
import { CookiePreferencesLink } from '@/features/telemetry/components/CookieConsent';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { HOW_IT_WORKS_ANCHOR } from './SiteHeader';

const linkClass = 'text-ink-2 hover:text-base-content text-[13px] text-left cursor-pointer';

function Column({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2.5 lg:w-[180px]">
            <h2 className="text-[13px] font-bold">{title}</h2>
            {children}
        </div>
    );
}

export function SiteFooter() {
    return (
        <footer className="border-line bg-base-100 border-t">
            <div className="mx-auto flex max-w-[1440px] flex-col gap-8 px-4 pt-10 pb-12 lg:flex-row lg:gap-10 lg:px-20 lg:pt-12 lg:pb-14">
                <div className="flex flex-col gap-2.5 lg:flex-1">
                    <Brand />
                    <p className="text-ink-3 max-w-[280px] text-[13px]">Catálogos con apartado y cuotas para negocios en Venezuela.</p>
                    <p className="text-ink-3 text-xs">© {new Date().getFullYear()} Apartar</p>
                </div>

                <Column title="Producto">
                    <Link href={`${route('home')}#${HOW_IT_WORKS_ANCHOR}`} className={linkClass}>
                        Cómo funciona
                    </Link>
                    <Link href={route('pricing')} className={linkClass}>
                        Precios
                    </Link>
                    <Link href={route('login')} className={linkClass}>
                        Entrar
                    </Link>
                </Column>

                <Column title="Clientes">
                    <Link href={route('portal.access')} className={linkClass}>
                        Mi cuenta
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
