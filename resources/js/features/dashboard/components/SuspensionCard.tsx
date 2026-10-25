import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type DashboardSuspension } from '@/types/dashboard';
import { cilCheckAlt, cilLockLocked } from '@coreui/icons';
import { Link } from '@inertiajs/react';

function day(date: string | null): string {
    return date === null ? '—' : `${date.slice(8, 10)}/${date.slice(5, 7)}`;
}

const KEEPS = [
    { title: 'Tus clientes no notan nada', detail: 'Ven sus apartados, suben comprobantes, reciben recordatorios y te escriben por chat.' },
    { title: 'Aprobar comprobantes', detail: 'Aprobarlos mantiene al día a tus clientes.' },
    { title: 'Consultar la cobranza', detail: 'Clientes, cuotas y saldos en modo lectura.' },
];

const PAUSED = [
    { title: 'Tus catálogos se despublicaron', detail: 'Quien abra su enlace verá «catálogo no disponible».' },
    { title: 'Crear productos, catálogos o apartados', detail: 'Tampoco puedes editar los existentes.' },
    { title: 'Notificaciones, exportaciones y ajustes', detail: 'Se reactivan apenas se confirme el pago.' },
];

export function SuspensionCard({ suspension, invoicesUrl }: { suspension: DashboardSuspension; invoicesUrl: string | null }) {
    const invoice = suspension.invoice;
    const steps = [
        { label: 'Vencida', date: day(invoice?.dueDate ?? null), done: true },
        { label: 'Fin de la gracia', date: day(suspension.graceEndsAt), done: true },
        { label: 'Suspendida', date: day(suspension.suspendedAt), done: true },
        { label: 'Pagas y se reactiva', date: 'Hoy', done: false },
    ];

    return (
        <section aria-label="Suscripción suspendida" className="flex flex-col gap-4">
            <div className="bg-base-100 border-error/30 shadow-card grid gap-5 rounded-2xl border p-5 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-h2">Paga tu factura para reactivar tu negocio</h2>
                        {invoice !== null ? (
                            <p className="text-ink-3 text-[13px]">
                                {invoice.kind} · Plan {invoice.planName}
                            </p>
                        ) : null}
                    </div>
                    <ol className="grid grid-cols-4 gap-2">
                        {steps.map((step, index) => (
                            <li key={step.label} className="flex flex-col gap-1.5">
                                <span
                                    className={cn(
                                        'h-1.5 rounded-full',
                                        step.done ? 'bg-error' : 'bg-success',
                                        index === steps.length - 1 && 'opacity-60',
                                    )}
                                />
                                <span className="text-xs font-semibold">{step.date}</span>
                                <span className="text-ink-3 text-xs">{step.label}</span>
                            </li>
                        ))}
                    </ol>
                    <p className="text-ink-2 text-[13px]">
                        Cuando aprobemos tu comprobante se levanta la suspensión. Tus catálogos no se publican solos: vuelve a publicarlos.
                    </p>
                </div>

                <div className="bg-surface-2 flex flex-col gap-2 rounded-xl p-4">
                    {invoice !== null ? (
                        <>
                            <p className="text-ink-3 text-xs">
                                {invoice.number} · vencida hace {invoice.daysOverdue} {invoice.daysOverdue === 1 ? 'día' : 'días'}
                            </p>
                            <p className="text-figure">{invoice.amount}</p>
                            {invoice.amountVes !== null ? <p className="text-ink-3 text-xs">{invoice.amountVes} · tasa de hoy</p> : null}
                        </>
                    ) : (
                        <p className="text-ink-2 text-[13px]">Tu comprobante está en revisión: te avisamos al aprobarlo.</p>
                    )}
                    {invoicesUrl !== null ? (
                        <>
                            <Link href={invoicesUrl} className="btn btn-primary btn-block mt-2">
                                Subir comprobante de pago
                            </Link>
                            <Link href={invoicesUrl} className="text-primary-ink text-center text-[13px] font-semibold hover:underline">
                                Ver datos de pago de Apartar
                            </Link>
                        </>
                    ) : (
                        <p className="text-ink-3 text-xs">Pídele a la persona dueña del negocio que pague la factura.</p>
                    )}
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
                <StatusList title="Sigue funcionando" items={KEEPS} ok />
                <StatusList title="En pausa hasta que pagues" items={PAUSED} ok={false} />
            </div>
        </section>
    );
}

function StatusList({ title, items, ok }: { title: string; items: readonly { title: string; detail: string }[]; ok: boolean }) {
    return (
        <div className="bg-base-100 border-line shadow-card flex flex-col gap-3 rounded-2xl border p-5">
            <h3 className="text-eyebrow text-ink-3">{title}</h3>
            <ul className="flex flex-col gap-3">
                {items.map((item) => (
                    <li key={item.title} className="flex gap-2.5">
                        <Icon icon={ok ? cilCheckAlt : cilLockLocked} size={16} className={cn('mt-0.5', ok ? 'text-success' : 'text-ink-3')} />
                        <span className="flex flex-col">
                            <span className="text-sm font-semibold">{item.title}</span>
                            <span className="text-ink-3 text-xs">{item.detail}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
