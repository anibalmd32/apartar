import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';

/**
 * La portada de la plataforma ([05 §7.1]).
 *
 * Dice qué es Apartar y ofrece dos caminos: empezar gratis o comparar los
 * planes. **Ninguna cifra**: los límites y los precios están en `/precios`,
 * que los lee de `plans` (`REQ-SUB-001` RB-8). Una cifra escrita aquí sería
 * una segunda copia de la matriz, y la que se queda vieja es siempre la del
 * `.tsx`.
 */
export default function Home() {
    return (
        <MarketingLayout title="Catálogos con apartado">
            <section className="flex flex-col items-center gap-6 py-10 text-center">
                <h1 className="max-w-2xl text-3xl font-bold sm:text-4xl">Tu catálogo, con apartado y cuotas, en un enlace de WhatsApp</h1>
                <p className="max-w-xl opacity-80">
                    Publica tus productos, comparte el enlace y deja que tus clientes aparten lo que quieren y lo paguen en cuotas. Tú llevas la
                    cobranza desde un solo sitio, con precios en dólares y en bolívares.
                </p>

                <div className="flex flex-wrap justify-center gap-3">
                    <Link href={route('register')} className="btn btn-primary">
                        Empezar gratis
                    </Link>
                    <Link href={route('pricing')} className="btn btn-ghost">
                        Ver planes y precios
                    </Link>
                </div>
            </section>

            <section className="grid gap-4 sm:grid-cols-3">
                <article className="bg-base-200 rounded-box p-6">
                    <h2 className="font-semibold">Un enlace por campaña</h2>
                    <p className="mt-2 text-sm opacity-80">Cada catálogo tiene su enlace, su orden y su fecha de fin, listo para compartir.</p>
                </article>
                <article className="bg-base-200 rounded-box p-6">
                    <h2 className="font-semibold">Apartado en cuotas</h2>
                    <p className="mt-2 text-sm opacity-80">Tus clientes apartan sin crear cuenta y ven su plan de pagos desde el primer momento.</p>
                </article>
                <article className="bg-base-200 rounded-box p-6">
                    <h2 className="font-semibold">Dólares y bolívares</h2>
                    <p className="mt-2 text-sm opacity-80">Cada precio se muestra en las dos monedas con la tasa del día, y su fecha a la vista.</p>
                </article>
            </section>
        </MarketingLayout>
    );
}
