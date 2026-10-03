import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type BusinessBlock as BusinessBlockData, type LayawaySummary } from '@/types/portal';
import { Link } from '@inertiajs/react';

/**
 * Lo que la persona tiene con **un** negocio (`REQ-LAY-020` RB-2, RB-5).
 *
 * El bloque lleva el nombre —y el logo, si el negocio tiene marca— en su
 * cabecera, porque cada deuda es con una empresa distinta: una lista que
 * mezclara apartados de dos negocios sería un error de fondo, no de
 * presentación. Los vivos van a la vista; los terminales, en un historial
 * plegado, que se abre solo si no hay ninguno vivo (SP-3).
 */

interface BusinessBlockProps {
    block: BusinessBlockData;
}

export function BusinessBlock({ block }: BusinessBlockProps) {
    const { business, totals, layaways, history, closed } = block;

    return (
        <section className="card bg-base-100 shadow-sm" aria-label={`Tus apartados en ${business.name}`}>
            <div className="card-body gap-4 p-4">
                <header className="flex items-center gap-3">
                    {business.brandingEnabled && business.logoUrl !== null ? (
                        <img src={business.logoUrl} alt="" className="h-10 w-10 rounded object-contain" />
                    ) : null}
                    <div className="min-w-0">
                        <h2 className="truncate text-lg font-semibold">{business.name}</h2>
                        {totals.nextDueDateLabel !== null ? (
                            <p className="text-sm opacity-70">Próximo vencimiento: {totals.nextDueDateLabel}</p>
                        ) : null}
                    </div>
                </header>

                {closed ? (
                    <p role="note" className="text-sm opacity-80">
                        {business.name} ya no opera en Apartar. Tus apartados se conservan aquí para que los consultes.
                    </p>
                ) : null}

                {totals.activeLayaways > 0 ? (
                    <dl className="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <dt className="opacity-70">Te falta pagar</dt>
                            <dd className="font-semibold">
                                <MoneyLabel money={totals.remaining} />
                            </dd>
                        </div>
                        <div>
                            <dt className="opacity-70">Cuotas vencidas</dt>
                            <dd className={totals.overdueInstallments > 0 ? 'text-error font-semibold' : 'font-semibold'}>
                                {totals.overdueInstallments}
                            </dd>
                        </div>
                    </dl>
                ) : null}

                {layaways.length > 0 ? (
                    <ul className="flex flex-col gap-2">
                        {layaways.map((layaway) => (
                            <LayawayItem key={layaway.code} layaway={layaway} />
                        ))}
                    </ul>
                ) : null}

                {history.length > 0 ? (
                    <details className="collapse-arrow collapse" open={layaways.length === 0}>
                        <summary className="collapse-title px-0 text-sm font-medium">Historial ({history.length})</summary>
                        <ul className="collapse-content flex flex-col gap-2 px-0">
                            {history.map((layaway) => (
                                <LayawayItem key={layaway.code} layaway={layaway} />
                            ))}
                        </ul>
                    </details>
                ) : null}
            </div>
        </section>
    );
}

function LayawayItem({ layaway }: { layaway: LayawaySummary }) {
    return (
        <li>
            <Link
                href={route('portal.layaways.show', { code: layaway.code })}
                className="border-base-300 hover:bg-base-200 flex flex-col gap-1 rounded-lg border p-3"
            >
                <span className="flex items-start justify-between gap-2">
                    <span className="font-medium">
                        {layaway.quantity > 1 ? `${layaway.quantity} × ` : ''}
                        {layaway.productName}
                    </span>
                    <span className="badge badge-ghost shrink-0">{layaway.statusLabel}</span>
                </span>
                <span className="text-sm opacity-70">
                    <span className="font-mono">{layaway.code}</span> · Te falta <MoneyLabel money={layaway.remaining} />
                </span>
            </Link>
        </li>
    );
}
