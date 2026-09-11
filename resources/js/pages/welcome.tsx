import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

/**
 * Portada provisional.
 *
 * Sustituye a las 788 líneas de bienvenida que traía el starter kit, que
 * violaban `max-lines` (`REQ-QAX-002` RB-1) y eran material de marketing de
 * Laravel, no de Apartar. La portada real llega con el módulo de marketing y
 * vivirá en `pages/marketing/`; esto solo mantiene la ruta `home` en pie y los
 * dos accesos que el andamiaje de autenticación necesita.
 */
export default function Welcome() {
    const { auth } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Apartar" />

            <main className="flex min-h-screen flex-col items-center justify-center gap-6 p-6 text-center">
                <div>
                    <h1 className="text-2xl font-semibold">Apartar</h1>
                    <p className="mt-2 text-sm opacity-70">Catálogos con apartado para tu negocio.</p>
                </div>

                <nav className="flex items-center gap-4 text-sm">
                    {auth.user ? (
                        <Link href={route('dashboard')} className="underline underline-offset-4">
                            Ir al backoffice
                        </Link>
                    ) : (
                        <>
                            <Link href={route('login')} className="underline underline-offset-4">
                                Entrar
                            </Link>
                            <Link href={route('register')} className="underline underline-offset-4">
                                Registrar negocio
                            </Link>
                        </>
                    )}
                </nav>
            </main>
        </>
    );
}
