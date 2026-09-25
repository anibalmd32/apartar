import { Alert } from '@/components/ui/alert';
import MarketingLayout from '@/layouts/marketing-layout';
import { type LegalProps } from '@/types/marketing';

/**
 * `/terminos` y `/privacidad` (`REQ-IDN-005`).
 *
 * **Mientras `drafted` sea `false`, esta página no es un documento legal y lo
 * dice arriba, en grande.** El formulario de apartado de `T-045` la enlazará
 * junto a una casilla que el cliente marca y que se guarda como evidencia; un
 * texto plausible aquí sería algo que alguien acepta sin que nadie lo haya
 * redactado. Lo que se pinta debajo es la lista de lo que el documento tiene
 * que cubrir según la spec, para quien lo escriba: referencias, no cláusulas.
 */
export default function Legal({ document }: LegalProps) {
    return (
        <MarketingLayout title={document.title}>
            <article className="mx-auto flex max-w-2xl flex-col gap-6">
                <h1 className="text-3xl font-bold">{document.title}</h1>

                {!document.drafted ? (
                    <>
                        <Alert tone="warning">
                            <strong>Documento pendiente de redacción.</strong> Esta página todavía no contiene el texto de este documento y no tiene
                            ningún valor legal. No aceptes nada en su nombre.
                        </Alert>

                        <section>
                            <h2 className="font-semibold">Qué tendrá que cubrir</h2>
                            <ul className="mt-2 list-inside list-disc text-sm opacity-80">
                                {document.mustCover.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        </section>
                    </>
                ) : null}
            </article>
        </MarketingLayout>
    );
}
