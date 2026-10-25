import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { HeroVisual } from '@/features/marketing/components/HeroVisual';
import { HOW_IT_WORKS_ANCHOR } from '@/features/marketing/components/SiteHeader';
import MarketingLayout from '@/layouts/marketing-layout';
import { cilArrowRight, cilCalendarCheck, cilChatBubble, cilCheckCircle, cilLink, cilSwapHorizontal } from '@coreui/icons';
import { Link } from '@inertiajs/react';

const VALUES = [
    {
        icon: cilLink,
        title: 'Un enlace por campaña',
        text: 'Arma catálogos por temporada u oferta y compártelos por WhatsApp, Instagram o donde vendas.',
    },
    {
        icon: cilCalendarCheck,
        title: 'Apartado en cuotas',
        text: 'Tu cliente paga la inicial y el resto en cuotas, y ve su plan de pagos desde el primer momento. Sin crear cuenta.',
    },
    {
        icon: cilSwapHorizontal,
        title: 'USD y Bs. a la tasa del día',
        text: 'Tus precios en dólares, con su equivalente en bolívares según BCV, paralelo o tu propia tasa.',
    },
];

const STEPS = [
    { title: 'Carga tus productos', text: 'Fotos, precio, inicial y número de cuotas.' },
    { title: 'Comparte tu catálogo', text: 'Un enlace bonito que se ve bien en WhatsApp.' },
    { title: 'Aprueba los pagos', text: 'Tus clientes suben el comprobante; tú lo apruebas en un toque.' },
];

const PERKS = ['Sin tarjeta', 'Plan gratis para empezar', 'Listo en minutos'];

export default function Home() {
    return (
        <MarketingLayout title="Catálogos con apartado">
            <section className="mx-auto flex max-w-[1440px] flex-col gap-8 px-4 pt-6 pb-10 lg:flex-row lg:items-center lg:gap-16 lg:px-20 lg:pt-[72px] lg:pb-[88px]">
                <div className="flex flex-col items-start gap-[22px] lg:flex-1">
                    <span className="bg-primary-soft text-primary-ink inline-flex items-center gap-1.5 rounded-full px-2.5 py-[3px] text-xs font-semibold">
                        <Icon icon={cilChatBubble} size={12} />
                        Hecho para vender por WhatsApp
                    </span>
                    <h1 className="max-w-[620px] text-[34px] leading-[1.05] font-extrabold tracking-[-0.04em] lg:text-[56px]">
                        Tu catálogo, con apartado y cuotas, en un solo enlace
                    </h1>
                    <p className="text-ink-2 max-w-[560px] text-base leading-[1.55] lg:text-[19px]">
                        Publica tus productos, comparte el enlace y deja que tus clientes aparten pagando una inicial y el resto en cuotas. Precios en
                        dólares y bolívares a la tasa del día.
                    </p>
                    <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                        <Link href={route('register')} className="btn btn-primary h-12 rounded-full px-5 text-[15px]">
                            Empezar gratis
                            <Icon icon={cilArrowRight} />
                        </Link>
                        <Link href={route('pricing')} className="btn h-12 rounded-full px-5 text-[15px]">
                            Ver planes y precios
                        </Link>
                    </div>
                    <ul className="text-ink-2 flex flex-wrap gap-x-4 gap-y-3 text-[13px] font-medium">
                        {PERKS.map((perk) => (
                            <li key={perk} className="flex items-center gap-1.5">
                                <Icon icon={cilCheckCircle} className="text-success" />
                                {perk}
                            </li>
                        ))}
                    </ul>
                </div>

                <HeroVisual className="lg:w-[520px] lg:shrink-0" />
            </section>

            <section
                aria-label="Lo que resuelve"
                className="mx-auto grid max-w-[1440px] grid-cols-1 gap-4 px-4 pb-10 lg:grid-cols-3 lg:gap-5 lg:px-20 lg:pt-6 lg:pb-20"
            >
                {VALUES.map((value) => (
                    <article key={value.title} className="border-line bg-base-100 shadow-card flex flex-col gap-3 rounded-2xl border p-6">
                        <IconChip icon={value.icon} size={44} className="rounded-[13px]" />
                        <h2 className="text-lg font-bold">{value.title}</h2>
                        <p className="text-ink-2 text-sm leading-[1.55]">{value.text}</p>
                    </article>
                ))}
            </section>

            <section id={HOW_IT_WORKS_ANCHOR} className="bg-base-100 scroll-mt-4 px-4 py-14 lg:px-20 lg:py-20">
                <div className="mx-auto flex max-w-[1128px] flex-col gap-7 lg:items-center">
                    <h2 className="text-[26px] font-extrabold tracking-[-0.03em] lg:text-[38px]">Así de simple</h2>
                    <ol className="grid w-full grid-cols-1 gap-7 lg:grid-cols-3 lg:gap-6">
                        {STEPS.map((step, index) => (
                            <li key={step.title} className="flex flex-col items-start gap-2.5">
                                <span className="bg-primary text-primary-content flex size-10 items-center justify-center rounded-full text-base font-extrabold">
                                    {index + 1}
                                </span>
                                <h3 className="text-lg font-bold">{step.title}</h3>
                                <p className="text-ink-2 max-w-[320px] text-sm">{step.text}</p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            <section className="px-4 py-10 lg:px-20 lg:py-20">
                <div className="bg-primary text-primary-content mx-auto flex max-w-[1280px] flex-col items-center gap-4 rounded-[28px] px-6 py-12 text-center lg:px-16 lg:py-14">
                    <h2 className="text-[28px] leading-tight font-extrabold tracking-[-0.03em] lg:text-[40px]">Empieza a vender en cuotas hoy</h2>
                    <p className="text-base opacity-85">Empieza gratis. Mejora cuando tu negocio crezca.</p>
                    <Link
                        href={route('register')}
                        className="inline-flex h-[50px] items-center justify-center rounded-full bg-white px-6 text-[15px] font-bold text-[#4338ca] hover:bg-white/90"
                    >
                        Crear mi catálogo gratis
                    </Link>
                </div>
            </section>
        </MarketingLayout>
    );
}
