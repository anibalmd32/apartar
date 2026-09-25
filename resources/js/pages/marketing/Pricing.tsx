import MarketingLayout from '@/layouts/marketing-layout';
import { formatLimit } from '@/lib/plan';
import { type PricingProps } from '@/types/marketing';
import { Link } from '@inertiajs/react';

/**
 * La página de precios (`REQ-SUB-001`).
 *
 * **Todo lo que hay aquí viene de `plans`** (RB-8): el nombre, el precio, los
 * siete límites y las funciones. Este archivo no tiene ninguna cifra, ningún
 * nombre de plan y ninguna comparación contra un código: ajustar un plan es un
 * `UPDATE` y esta página lo enseña en la siguiente visita.
 *
 * ## `null` es «ilimitado», y lo dice `formatLimit()` (SP-2)
 *
 * Es la misma columna con dos significados opuestos —`1` es un techo, `null`
 * es que no lo hay— y pintar `null` como `0` convertiría el plan más amplio en
 * el más restringido. La traducción está escrita y probada una vez en
 * `lib/plan.ts`; aquí no hay ningún `?? 0`.
 *
 * ## Sin funciones de pago no es una sección vacía (SP-1)
 *
 * Un plan sin `features` se dice con una frase, no con un encabezado sin nada
 * debajo.
 */
export default function Pricing({ plans }: PricingProps) {
    return (
        <MarketingLayout title="Planes y precios">
            <header className="mb-8 text-center">
                <h1 className="text-3xl font-bold">Planes y precios</h1>
                <p className="mt-2 opacity-80">Compara lo que incluye cada plan. Todos traen el sistema de apartado.</p>
            </header>

            <ul className="grid gap-4 md:grid-cols-3">
                {plans.map((plan) => (
                    <li key={plan.code} className="card bg-base-200">
                        <div className="card-body gap-4">
                            <div>
                                <h2 className="card-title">{plan.name}</h2>
                                <p className="mt-1 text-2xl font-bold">{plan.priceLabel}</p>
                            </div>

                            <dl className="flex flex-col gap-1 text-sm">
                                {plan.limits.map((limit) => (
                                    <div key={limit.key} className="flex justify-between gap-2">
                                        <dt className="opacity-80">{limit.label}</dt>
                                        <dd className="font-medium">{formatLimit(limit.limit)}</dd>
                                    </div>
                                ))}
                            </dl>

                            {plan.features.length > 0 ? (
                                <div>
                                    <h3 className="text-sm font-semibold">Incluye</h3>
                                    <ul className="mt-1 list-inside list-disc text-sm">
                                        {plan.features.map((feature) => (
                                            <li key={feature}>{feature}</li>
                                        ))}
                                    </ul>
                                </div>
                            ) : (
                                <p className="text-sm opacity-70">Sin funciones de pago: lo esencial para empezar a vender.</p>
                            )}

                            {plan.isFree ? (
                                <Link href={route('register')} className="btn btn-primary mt-auto">
                                    Empezar gratis
                                </Link>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ul>
        </MarketingLayout>
    );
}
