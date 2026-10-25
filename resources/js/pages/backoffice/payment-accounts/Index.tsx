import { Alert } from '@/components/ui/alert';
import { Meter } from '@/components/ui/meter';
import { SectionCard } from '@/components/ui/section-card';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import { PaymentAccountForm } from '@/features/tenancy/components/PaymentAccountForm';
import { PaymentAccountRow } from '@/features/tenancy/components/PaymentAccountRow';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatUsage } from '@/lib/plan';
import { type PaymentAccountView, type PaymentMethodDefinition } from '@/types/payment';
import { type UsageState } from '@/types/plan';
import { useState } from 'react';

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
        <BackofficeLayout
            title="Cuentas de cobro"
            description="Dónde te pagan tus clientes. Es lo que ven al apartar y al reportar un pago."
            actions={
                <div className="bg-base-100 border-line shadow-card w-64 rounded-xl border px-4 py-3">
                    <Meter
                        label="Cuentas activas"
                        value={formatUsage(usage.used, usage.limit)}
                        percentage={usage.percentage}
                        warn={usage.isAtLimit || (usage.percentage ?? 0) >= 80}
                    />
                </div>
            }
        >
            {active.length === 0 ? (
                <Alert tone="warning" title="No tienes ninguna cuenta de cobro activa">
                    Tus catálogos publicados siguen publicados, pero tus clientes no verán a dónde pagarte.
                </Alert>
            ) : null}

            {!usage.isAtLimit ? <LimitBanner metric="payment_accounts" /> : null}

            <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-6">
                <section aria-label="Tus cuentas" className="flex flex-col gap-3">
                    {accounts.length === 0 ? (
                        <p className="bg-base-100 border-line text-ink-2 rounded-2xl border border-dashed p-8 text-center text-sm">
                            Todavía no has declarado ninguna. Añade la primera con el formulario.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-3">
                            {accounts.map((account) => (
                                <PaymentAccountRow
                                    key={account.id}
                                    account={account}
                                    atLimit={usage.isAtLimit}
                                    onEdit={() => setEditing(account.id)}
                                />
                            ))}
                        </ul>
                    )}

                    <p className="text-ink-3 text-xs">
                        Se muestran en este orden a tus clientes. Eliminar una cuenta con comprobantes la desactiva en vez de borrarla, para no perder
                        el rastro de a dónde se pagó.
                    </p>
                </section>

                <SectionCard title={editable ? 'Editar cuenta' : 'Nueva cuenta de cobro'} className="lg:sticky lg:top-24">
                    {usage.isAtLimit && editable === undefined ? (
                        <Alert tone="warning" className="flex-col items-start gap-2">
                            <span>Tu plan no admite más cuentas de cobro activas. Puedes desactivar alguna o cambiar de plan.</span>
                            <a className="btn btn-sm" href="/backoffice/suscripcion">
                                Ver planes
                            </a>
                        </Alert>
                    ) : null}

                    {editable ? (
                        <PaymentAccountForm key={editable.id} methods={methods} account={editable} onDone={() => setEditing(null)} />
                    ) : (
                        <PaymentAccountForm methods={methods} atLimit={usage.isAtLimit} />
                    )}

                    <p className="text-ink-3 text-xs">
                        El titular y su cédula son parte del dato de pago: sin ellos tu cliente no puede completar la transferencia.
                    </p>
                </SectionCard>
            </div>
        </BackofficeLayout>
    );
}
