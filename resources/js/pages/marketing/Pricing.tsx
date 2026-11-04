import { Icon } from '@/components/ui/icon';
import { formatRate, shortDay } from '@/features/marketing/board/board-math';
import { PlanCard } from '@/features/marketing/components/PlanCard';
import { SignupCta } from '@/features/marketing/components/SignupCta';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
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

const COLUMNS: Record<number, string> = { 1: 'lg:grid-cols-1 lg:max-w-[560px]', 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3' };

export default function Pricing({ plans, rate = null }: PricingProps) {
    const featuredIndex = plans.findIndex((plan) => !plan.isFree);
    const paid = featuredIndex !== -1;
    const faqs = paid ? FAQS : FAQS.filter((faq) => faq.question !== '¿Cómo pago mi plan?');

    return (
        <MarketingLayout title="Planes y precios">
            <header className="mx-auto flex max-w-[1280px] flex-col items-start gap-5 px-4 pt-8 pb-12 sm:px-6 lg:px-12 lg:pt-12 lg:pb-16">
                {paid && rate !== null ? (
                    <p className="font-hand text-xl sm:text-2xl">
                        Tasa BCV del {shortDay(rate.date)}: <span className="text-pz-blue">{formatRate(rate.rate)}</span>
                    </p>
                ) : null}
                <h1 className="font-vinyl max-w-[14ch] text-[clamp(3rem,1.8rem+5vw,5.5rem)]">Precios simples para crecer</h1>
                <p className="text-pz-ink-2 max-w-[52ch] text-lg leading-relaxed sm:text-xl">
                    {paid
                        ? 'Empieza gratis. Cuando tu negocio crezca, mejora tu plan y paga en bolívares o dólares, sin tarjeta internacional.'
                        : 'Empieza gratis, sin tarjeta y sin período de prueba que se acabe.'}
                </p>
            </header>

            <div className="mx-auto max-w-[1280px] px-4 pb-16 sm:px-6 lg:px-12 lg:pb-24">
                <ul
                    className={cn(
                        'divide-pz-rule grid grid-cols-1 divide-y-2 lg:divide-x-2 lg:divide-y-0',
                        COLUMNS[Math.min(plans.length, 3)] ?? 'lg:grid-cols-3',
                    )}
                >
                    {plans.map((plan, index) => (
                        <PlanCard
                            key={plan.code}
                            plan={plan}
                            previous={plans[index - 1]}
                            featured={index === featuredIndex}
                            rate={rate?.rate ?? null}
                        />
                    ))}
                </ul>
            </div>

            <hr aria-hidden="true" className="border-pz-rule-soft mx-auto max-w-[1180px] border-t-2" />

            <section aria-labelledby="preguntas" className="mx-auto max-w-[1280px] px-4 py-16 sm:px-6 lg:px-12 lg:py-24">
                <h2 id="preguntas" className="font-vinyl text-[2.75rem] sm:text-6xl">
                    Preguntas frecuentes
                </h2>
                <div className="mt-8 max-w-[860px]">
                    {faqs.map((faq, index) => (
                        <details key={faq.question} className="group pz-rule-dashed py-2" open={index === 0}>
                            <summary className="flex min-h-12 cursor-pointer list-none items-center gap-4 text-xl font-bold [font-stretch:90%] [&::-webkit-details-marker]:hidden">
                                <span className="flex-1">{faq.question}</span>
                                <Icon icon={cilPlus} size={20} className="text-pz-blue group-open:hidden" />
                                <Icon icon={cilMinus} size={20} className="text-pz-blue hidden group-open:block" />
                            </summary>
                            <p className="text-pz-ink-2 max-w-[62ch] pb-3 text-lg leading-relaxed">{faq.answer}</p>
                        </details>
                    ))}
                </div>
            </section>

            <hr aria-hidden="true" className="border-pz-rule-soft mx-auto max-w-[1180px] border-t-2" />

            <section
                aria-labelledby="pricing-closing"
                className="mx-auto flex max-w-[1280px] flex-col items-start gap-5 px-4 py-20 sm:px-6 lg:px-12 lg:py-24"
            >
                <h2 id="pricing-closing" className="font-vinyl text-[3rem] sm:text-[4.5rem]">
                    Abre tu catálogo hoy
                </h2>
                <p className="text-pz-ink-2 max-w-[52ch] text-lg leading-relaxed">
                    Crea tu cuenta en minutos, comparte el enlace por WhatsApp y empieza a recibir apartados.
                </p>
                <SignupCta className="mt-2 min-h-14 px-8 text-lg" />
            </section>
        </MarketingLayout>
    );
}
