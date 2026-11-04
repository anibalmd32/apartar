import { HOW_IT_WORKS_ANCHOR } from '@/features/marketing/components/SiteHeader';
import { MarkerCircle } from './MarkerCircle';

const STEPS = [
    { title: 'Carga tus productos', text: 'Fotos, precio, inicial y número de cuotas.', note: 'una vez' },
    { title: 'Comparte tu catálogo', text: 'Un enlace que se ve bien en WhatsApp, Instagram o donde vendas.', note: 'apartar.app/c/temporada' },
    { title: 'Aprueba los pagos', text: 'Tus clientes suben el comprobante; tú lo apruebas en un toque.', note: 'y listo' },
];

export function BoardSteps() {
    return (
        <section
            id={HOW_IT_WORKS_ANCHOR}
            aria-labelledby="como-funciona-titulo"
            className="mx-auto max-w-[1280px] scroll-mt-6 px-4 py-16 sm:px-6 lg:px-12 lg:py-24"
        >
            <h2 id="como-funciona-titulo" className="font-vinyl text-[2.75rem] sm:text-6xl lg:text-[4.5rem]">
                Así de simple
            </h2>

            <ol className="mt-12 grid gap-10 md:grid-cols-3 md:gap-8">
                {STEPS.map((step, index) => (
                    <li key={step.title} className="flex flex-col gap-3">
                        <span className="font-hand text-pz-blue text-[2.5rem] leading-none">
                            <MarkerCircle tone="blue" className="px-[0.55em]">
                                {index + 1}
                            </MarkerCircle>
                        </span>
                        <h3 className="mt-2 text-2xl font-extrabold [font-stretch:85%]">{step.title}</h3>
                        <p className="text-pz-ink-2 max-w-[32ch] text-lg leading-relaxed">{step.text}</p>
                        <p className="font-hand text-pz-ink-3 text-xl">{step.note}</p>
                    </li>
                ))}
            </ol>
        </section>
    );
}
