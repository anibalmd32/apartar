import { Icon } from '@/components/ui/icon';
import { caracasToday, type DailyRate } from '@/features/marketing/board/board-math';
import { BoardSteps } from '@/features/marketing/board/BoardSteps';
import { BothSides } from '@/features/marketing/board/BothSides';
import { LayawaySumBoard } from '@/features/marketing/board/LayawaySumBoard';
import { RateHistory } from '@/features/marketing/board/RateHistory';
import { RateLine } from '@/features/marketing/board/RateLine';
import { CTA_SHAPE, SignupCta } from '@/features/marketing/components/SignupCta';
import MarketingLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { cilCheckAlt } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface HomeProps {
    rates?: DailyRate[];
}

const PERKS = ['Sin tarjeta', 'Plan gratis para empezar', 'Listo en minutos'];

function Perks({ className }: { className?: string }) {
    return (
        <ul className={cn('font-hand flex flex-col gap-1.5 text-xl sm:flex-row sm:flex-wrap sm:gap-x-6', className)}>
            {PERKS.map((perk) => (
                <li key={perk} className="flex items-center gap-2">
                    <Icon icon={cilCheckAlt} size={20} className="text-pz-green" />
                    {perk}
                </li>
            ))}
        </ul>
    );
}

function Divider() {
    return <hr aria-hidden="true" className="border-pz-rule-soft mx-auto max-w-[1180px] border-t-2" />;
}

export default function Home({ rates = [] }: HomeProps) {
    const today = caracasToday();
    const rate = rates[0]?.rate ?? null;

    return (
        <MarketingLayout title="Catálogos con apartado">
            <section className="mx-auto grid max-w-[1440px] gap-12 px-4 pt-6 pb-16 sm:px-6 lg:grid-cols-[1.3fr_1fr] lg:gap-14 lg:px-12 lg:pt-10 lg:pb-24 xl:gap-20">
                <div className="flex flex-col items-start">
                    <RateLine today={today} rates={rates} />
                    <h1 className="font-vinyl mt-6 text-[clamp(3.25rem,1.6rem+6.4vw,6rem)] text-balance lg:mt-8">
                        Se aparta con <span className="text-pz-blue">inicial.</span> El resto, en cuotas.
                    </h1>
                    <p className="text-pz-ink-2 mt-6 max-w-[46ch] text-lg leading-relaxed sm:text-xl">
                        Tu catálogo en un enlace. Tu cliente aparta, ve cuánto le falta y te paga a ti, en dólares o bolívares a la tasa del día.
                    </p>
                    <div className="mt-8 flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                        <SignupCta className="min-h-14 px-7 text-lg" />
                        <Link href={route('pricing')} className={cn(CTA_SHAPE, 'pz-btn-line min-h-14 px-7 text-lg')}>
                            Ver planes y precios
                        </Link>
                    </div>
                    <Perks className="mt-6" />
                </div>

                <LayawaySumBoard today={today} rate={rate} className="lg:mt-24" />
            </section>

            <Divider />
            <BothSides today={today} rate={rate} />
            <Divider />
            <BoardSteps />
            <Divider />
            <RateHistory rates={rates} />
            <Divider />

            <section
                aria-labelledby="empieza"
                className="mx-auto flex max-w-[1280px] flex-col items-start gap-5 px-4 py-20 sm:px-6 lg:items-center lg:px-12 lg:py-28 lg:text-center"
            >
                <h2 id="empieza" className="font-vinyl max-w-[16ch] text-[3rem] sm:text-[4.5rem] lg:text-[5.5rem]">
                    Empieza a vender en cuotas hoy
                </h2>
                <p className="text-pz-ink-2 text-lg sm:text-xl">Sin tarjeta y sin período de prueba.</p>
                <SignupCta className="mt-3 min-h-14 px-8 text-lg" />
            </section>
        </MarketingLayout>
    );
}
