import { Icon } from '@/components/ui/icon';
import { PlanCard } from '@/features/marketing/components/PlanCard';
import MarketingLayout from '@/layouts/marketing-layout';
import { type PricingProps } from '@/types/marketing';
import { cilMinus, cilPlus } from '@coreui/icons';

const FAQS = [
    {
        question: '¿Mis clientes pagan algo a Apartar?',
        answer: 'No. Tus clientes te pagan a ti, directo a tus cuentas. Apartar solo cobra el plan al negocio.',
    },
    {
        question: '¿Cómo pago mi plan?',
        answer: 'Precios en dólares, cada mes. Se paga con pago móvil o transferencia, también en bolívares a la tasa del día: sin tarjeta internacional.',
    },
    {
        question: '¿Tengo que pagar para empezar?',
        answer: 'No. Empiezas gratis, sin tarjeta y sin período de prueba que se acabe. El plan Gratis trae el sistema de apartado completo.',
    },
];

export default function Pricing({ plans }: PricingProps) {
    const featuredIndex = plans.findIndex((plan) => !plan.isFree);
    const paid = featuredIndex !== -1;
    const faqs = paid ? FAQS : FAQS.filter((faq) => faq.question !== '¿Cómo pago mi plan?');

    return (
        <MarketingLayout title="Planes y precios">
            <header className="flex flex-col items-center gap-3.5 px-4 pt-8 pb-8 text-center lg:px-20 lg:pt-16 lg:pb-10">
                <span className="bg-primary-soft text-primary-ink rounded-full px-2.5 py-[3px] text-xs font-semibold">Sin tarjeta internacional</span>
                <h1 className="text-[34px] leading-tight font-extrabold tracking-[-0.04em] lg:text-5xl">Precios simples para crecer</h1>
                <p className="text-ink-2 max-w-[640px] text-base leading-normal lg:text-lg">
                    {paid
                        ? 'Empieza gratis. Cuando tu negocio crezca, mejora tu plan y paga en bolívares o dólares.'
                        : 'Empieza gratis, sin tarjeta y sin período de prueba que se acabe.'}
                </p>
            </header>

            <ul className="mx-auto grid max-w-[1440px] grid-cols-1 items-start gap-5 px-4 pb-14 md:grid-cols-2 lg:auto-cols-fr lg:grid-flow-col lg:grid-cols-none lg:px-20">
                {plans.map((plan, index) => (
                    <PlanCard key={plan.code} plan={plan} featured={index === featuredIndex} />
                ))}
            </ul>

            <section className="bg-base-100 px-4 pt-14 pb-20">
                <div className="mx-auto flex max-w-[880px] flex-col gap-3">
                    <h2 className="mb-1 text-[26px] font-extrabold tracking-[-0.03em] lg:text-[32px]">Preguntas frecuentes</h2>
                    {faqs.map((faq, index) => (
                        <details key={faq.question} className="group bg-base-200 rounded-[14px] px-5 py-[18px]" open={index === 0}>
                            <summary className="flex min-h-11 cursor-pointer list-none items-center gap-3 text-base font-bold [&::-webkit-details-marker]:hidden">
                                <span className="flex-1">{faq.question}</span>
                                <Icon icon={cilPlus} className="text-ink-3 group-open:hidden" />
                                <Icon icon={cilMinus} className="hidden group-open:block" />
                            </summary>
                            <p className="text-ink-2 mt-2 text-sm leading-[1.55]">{faq.answer}</p>
                        </details>
                    ))}
                </div>
            </section>
        </MarketingLayout>
    );
}
