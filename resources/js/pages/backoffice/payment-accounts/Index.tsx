import { Alert } from '@/components/ui/alert';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { PaymentAccountForm } from '@/features/tenancy/components/PaymentAccountForm';
import { PaymentAccountRow } from '@/features/tenancy/components/PaymentAccountRow';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type PaymentAccountView, type PaymentMethodDefinition } from '@/types/payment';
import { type UsageState } from '@/types/plan';
import { useState } from 'react';

/**
 * Cuentas de cobro: dónde le pagan al negocio (`REQ-ORG-007`).
 *
 * Es la pantalla que hace posible cobrar. Sin una cuenta declarada, un cliente
 * que quiere apartar no sabe a dónde transferir y un comprobante no tiene a qué
 * cuenta apuntar; por eso publicar un catálogo exigirá al menos una activa
 * (`REQ-CAT-022`, que implementa `T-032`).
 *
 * Dos avisos viven aquí y no en el formulario, porque hablan del **conjunto** y
 * no de lo que se está escribiendo:
 *
 *  - **sin ninguna cuenta activa**, los clientes no ven a dónde pagar. Se avisa
 *    y no se bloquea nada: quedarse sin cuentas activas es una situación
 *    legítima —se cerró la del banco y aún no está la nueva— y **no despublica
 *    ningún catálogo** (SP-4). Un ajuste rutinario que tumbara la vitrina sería
 *    un castigo desproporcionado;
 *  - **con el plan lleno**, el alta se deshabilita con el motivo al lado en vez
 *    de dejar que la pulsen y reciban un `422` (SP-2). No sustituye a la
 *    comprobación —entre pintar y pulsar cabe otra petición, y por eso
 *    `LimitEnforcer` vuelve a mirar con el contador bloqueado— pero evita el
 *    callejón.
 */

interface PaymentAccountsPageProps {
    accounts: PaymentAccountView[];
    methods: PaymentMethodDefinition[];
    usage: UsageState;
}

export default function PaymentAccountsIndex({ accounts, methods, usage }: PaymentAccountsPageProps) {
    const [editing, setEditing] = useState<number | null>(null);

    const active = accounts.filter((account) => account.isActive);
    const editable = accounts.find((account) => account.id === editing);

    return (
        <BackofficeLayout title="Cuentas de cobro" description="Dónde te pagan tus clientes. Es lo que ven al apartar y al reportar un pago.">
            {active.length === 0 ? (
                <Alert tone="warning">
                    No tienes ninguna cuenta de cobro activa. Tus catálogos publicados siguen publicados, pero tus clientes no verán a dónde pagarte.
                </Alert>
            ) : null}

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <header className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="card-title text-base">{editable ? 'Editar cuenta' : 'Añadir una cuenta'}</h2>
                        <p className="text-sm opacity-70">{formatUsage(usage.used, usage.limit)} cuentas activas</p>
                    </header>

                    {usage.isAtLimit && editable === undefined ? (
                        <Alert tone="warning" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                            <span>Tu plan no admite más cuentas de cobro activas. Puedes desactivar alguna o cambiar de plan.</span>

                            <a className="btn btn-sm btn-warning" href="/backoffice/suscripcion">
                                Ver planes
                            </a>
                        </Alert>
                    ) : null}

                    {/* RB-7 · entre el 80 % y el techo; en el techo, el aviso de arriba. */}
                    {!usage.isAtLimit ? <LimitBanner metric="payment_accounts" /> : null}

                    {editable ? (
                        <PaymentAccountForm key={editable.id} methods={methods} account={editable} onDone={() => setEditing(null)} />
                    ) : (
                        <PaymentAccountForm methods={methods} atLimit={usage.isAtLimit} />
                    )}

                    <p className="text-xs opacity-70">
                        El titular y su cédula son parte del dato de pago: sin ellos tu cliente no puede completar la transferencia.
                    </p>
                </div>
            </section>

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <h2 className="card-title text-base">Tus cuentas</h2>

                    {accounts.length === 0 ? (
                        <p className="text-sm opacity-70">Todavía no has declarado ninguna. Añade la primera arriba.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th scope="col">Método</th>
                                        <th scope="col">Dato de pago</th>
                                        <th scope="col">Titular</th>
                                        <th scope="col">Estado</th>
                                        <th scope="col" className="text-right">
                                            Acciones
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {accounts.map((account) => (
                                        <PaymentAccountRow
                                            key={account.id}
                                            account={account}
                                            atLimit={usage.isAtLimit}
                                            onEdit={() => setEditing(account.id)}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <p className="text-xs opacity-70">
                        Se muestran en este orden a tus clientes. Eliminar una cuenta con comprobantes la desactiva en vez de borrarla, para no perder
                        el rastro de a dónde se pagó.
                    </p>
                </div>
            </section>
        </BackofficeLayout>
    );
}
