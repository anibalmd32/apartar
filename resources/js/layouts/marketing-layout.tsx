import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * El marco de las páginas de la plataforma: portada, precios y legales
 * ([05 §7.1]).
 *
 * Es la primera cara que ve alguien que **todavía no es cliente**, así que la
 * navegación son tres caminos y nada más: ver precios, entrar si ya tiene
 * cuenta y empezar gratis, que lleva al registro (`REQ-SUB-001` happy path 2).
 * Las legales van al pie, que es donde se buscan.
 */

interface MarketingLayoutProps {
    title: string;
    children: ReactNode;
}

export default function MarketingLayout({ title, children }: MarketingLayoutProps) {
    const { auth } = usePage<SharedData>().props;

    return (
        <div className="bg-base-100 flex min-h-screen flex-col">
            <Head title={title} />

            <header className="border-base-300 border-b">
                <nav className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <Link href={route('home')} className="text-lg font-semibold">
                        Apartar
                    </Link>

                    <div className="flex items-center gap-2">
                        <Link href={route('pricing')} className="btn btn-ghost btn-sm">
                            Precios
                        </Link>
                        {auth?.user ? (
                            <Link href={route('dashboard')} className="btn btn-ghost btn-sm">
                                Ir al backoffice
                            </Link>
                        ) : (
                            <>
                                <Link href={route('login')} className="btn btn-ghost btn-sm">
                                    Entrar
                                </Link>
                                <Link href={route('register')} className="btn btn-primary btn-sm">
                                    Empezar gratis
                                </Link>
                            </>
                        )}
                    </div>
                </nav>
            </header>

            <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-10">{children}</main>

            <footer className="border-base-300 border-t">
                <div className="mx-auto flex max-w-6xl flex-wrap gap-4 px-4 py-6 text-sm opacity-70">
                    <Link href={route('legal.terms')} className="link link-hover">
                        Términos
                    </Link>
                    <Link href={route('legal.privacy')} className="link link-hover">
                        Privacidad
                    </Link>
                </div>
            </footer>
        </div>
    );
}
