import { HOW_IT_WORKS_ANCHOR } from '@/features/marketing/components/SiteHeader';

const STEPS = [
    { when: 'Hoy, una sola vez', title: 'Carga tus productos', text: 'Fotos, precio, inicial y número de cuotas.' },
    {
        when: 'Cuando quieras vender',
        title: 'Comparte tu catálogo',
        text: 'Un enlace que se ve bien en WhatsApp, Instagram o donde vendas.',
        example: 'apartar.app/c/temporada',
    },
    {
        when: 'Cada vez que te paguen',
        title: 'Aprueba el comprobante',
        text: 'Tu cliente lo sube; tú lo apruebas en un toque y la cuota queda tachada.',
    },
];

export function BoardSteps() {
    return (
        <section
            id={HOW_IT_WORKS_ANCHOR}
            aria-labelledby="como-funciona-titulo"
            className="mx-auto grid max-w-[1280px] scroll-mt-6 gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1fr_1.6fr] lg:gap-16 lg:px-12 lg:py-24"
        >
            <h2 id="como-funciona-titulo" className="font-vinyl text-[2.75rem] sm:text-6xl lg:text-[4.5rem]">
                Así de simple
            </h2>

            <ol className="border-pz-rule relative flex flex-col border-l-2 pl-6 sm:pl-10">
                {STEPS.map((step) => (
                    <li key={step.title} className="pz-rule-dashed group relative grid gap-1 py-6 first:pt-1 sm:grid-cols-[12rem_1fr] sm:gap-6">
                        <span
                            aria-hidden="true"
                            className="bg-pz-blue absolute top-8 -left-[calc(1.5rem+6px)] size-2.5 rounded-full group-first:top-3 sm:-left-[calc(2.5rem+6px)]"
                        />
                        <p className="font-hand text-pz-blue text-xl leading-tight sm:text-2xl">{step.when}</p>
                        <div className="flex flex-col gap-1.5">
                            <h3 className="text-2xl font-extrabold [font-stretch:85%]">{step.title}</h3>
                            <p className="text-pz-ink-2 max-w-[44ch] text-lg leading-relaxed">{step.text}</p>
                            {step.example ? (
                                <p className="font-hand text-pz-ink-3 text-xl underline decoration-2 underline-offset-4">{step.example}</p>
                            ) : null}
                        </div>
                    </li>
                ))}
            </ol>
        </section>
    );
}
