import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { SectionCard } from '@/components/ui/section-card';
import { cn } from '@/lib/utils';
import { type DashboardMetrics } from '@/types/dashboard';
import { cilBasket, cilCheckAlt, cilCheckCircle, cilDescription } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export function SetupChecklist({ setup }: { setup: NonNullable<DashboardMetrics['setup']> }) {
    const steps: readonly { done: boolean; label: string; hint: string; href: string | null; action: string }[] = [
        {
            done: setup.emailVerified,
            label: 'Verifica tu correo',
            hint: 'Te enviamos un enlace al crear el negocio. Sin él no podrás recuperar la cuenta.',
            href: null,
            action: '',
        },
        {
            done: setup.paymentAccount,
            label: 'Añade una cuenta de cobro',
            hint: 'Dónde te pagan tus clientes: pago móvil, transferencia o Zelle. Aparece en cada apartado.',
            href: '/backoffice/cuentas-de-cobro',
            action: 'Agregar cuenta',
        },
        {
            done: setup.product,
            label: 'Carga tu primer producto',
            hint: 'Precio, inicial y número de cuotas. Con uno basta para empezar.',
            href: '/backoffice/productos/crear',
            action: 'Crear producto',
        },
        {
            done: setup.publishedCatalog,
            label: 'Publica tu primer catálogo',
            hint: 'Compártelo por WhatsApp o Instagram y tus clientes apartan solos.',
            href: '/backoffice/catalogos',
            action: 'Crear catálogo',
        },
    ];
    const done = steps.filter((step) => step.done).length;
    const next = steps.findIndex((step) => !step.done);

    return (
        <SectionCard
            title="Para empezar a vender"
            aria-label="Para empezar"
            subtitle="Te toma unos diez minutos."
            action={
                <span>
                    {done} de {steps.length}
                </span>
            }
        >
            <span className="bg-base-300 block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                <span className="bg-primary block h-full rounded-full" style={{ width: `${(done / steps.length) * 100}%` }} />
            </span>

            <ol className="flex flex-col gap-1">
                {steps.map((step, index) => {
                    const current = index === next;

                    return (
                        <li
                            key={step.label}
                            className={cn(
                                'flex flex-wrap items-center gap-3 rounded-xl border p-3.5',
                                current ? 'border-primary/40 bg-primary-soft/60' : 'border-transparent',
                            )}
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'flex size-7 shrink-0 items-center justify-center rounded-full text-[13px] font-bold',
                                    step.done && 'bg-success text-success-content',
                                    !step.done && current && 'bg-primary text-primary-content',
                                    !step.done && !current && 'bg-surface-2 text-ink-2',
                                )}
                            >
                                {step.done ? <Icon icon={cilCheckAlt} size={14} /> : index + 1}
                            </span>
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                {step.done || step.href === null ? (
                                    <span className={cn('text-sm font-semibold', step.done && 'text-ink-3 line-through')}>{step.label}</span>
                                ) : (
                                    <Link href={step.href} className="text-sm font-semibold hover:underline">
                                        {step.label}
                                    </Link>
                                )}
                                <span className="text-ink-3 text-xs">{step.hint}</span>
                            </span>
                            {step.done ? (
                                <span className="badge badge-success">Listo</span>
                            ) : step.href !== null ? (
                                <Link href={step.href} className={current ? 'btn btn-primary btn-sm' : 'btn btn-sm'} aria-hidden="true" tabIndex={-1}>
                                    {step.action}
                                </Link>
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </SectionCard>
    );
}

export function HowItWorks() {
    const items = [
        { icon: cilBasket, title: 'Tu cliente aparta', text: 'Elige el producto en tu catálogo y tiene un plazo para pagar la inicial.' },
        { icon: cilDescription, title: 'Sube cada comprobante', text: 'Desde su cuenta, sin escribirte por WhatsApp.' },
        { icon: cilCheckCircle, title: 'Tú apruebas y listo', text: 'Apartar aplica el pago a la cuota y lleva el saldo por ti.' },
    ];

    return (
        <SectionCard title="Así funciona Apartar">
            <ul className="flex flex-col gap-4">
                {items.map((item, index) => (
                    <li key={item.title} className="flex items-start gap-3">
                        <IconChip icon={item.icon} tone={index === 2 ? 'success' : 'primary'} />
                        <span className="flex flex-col gap-0.5">
                            <span className="text-sm font-semibold">{item.title}</span>
                            <span className="text-ink-3 text-xs">{item.text}</span>
                        </span>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}
