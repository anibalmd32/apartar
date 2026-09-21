import { Button } from '@/components/ui/button';
import { type CatalogVisibility } from '@/types/catalog';
import { useEffect, useState } from 'react';

/**
 * El enlace del catálogo, listo para pegar en WhatsApp
 * (`REQ-CAT-022` RB-1, `REQ-BOF-024` RB-5 y RB-6).
 *
 * ## Se enseña la URL entera, no el slug
 *
 * Es lo que RB-5 pide con esas palabras, y la diferencia es la que hay entre
 * copiar algo que funciona y copiar algo que hay que completar de memoria.
 * Además el slug **puede no ser el que se escribió**: es único en toda la
 * plataforma (RB-1), así que si otro negocio ya tenía «ofertas-septiembre»,
 * este catálogo se quedó con `ofertas-septiembre-2` sin que nadie lo avisara.
 * La desambiguación es silenciosa a propósito —rechazar el nombre sería
 * peor—, y por eso tiene que **verse** antes de difundirlo, no descubrirse
 * después de pegarlo en un grupo.
 *
 * ## Y la previsualización explica qué va a ver quien lo reciba
 *
 * RB-5 pide «una previsualización de cómo se verá el enlace al compartirlo».
 * Lo que se pinta es la tarjeta que arma WhatsApp con los metadatos Open Graph
 * del catálogo (`REQ-CAT-021` RB-10): el nombre del catálogo y la URL. No es
 * una captura del mensajero ni pretende serlo; es lo que permite darse cuenta
 * de que el catálogo se llama «Sin título» antes de mandarlo a doscientas
 * personas.
 *
 * ## La confirmación de copiado no se queda puesta
 *
 * Dos segundos y vuelve. Un «copiado» permanente deja de decir si la última
 * pulsación funcionó, que es exactamente lo que se quiere saber al volver a
 * pulsarlo.
 */

interface ShareLinkFieldProps {
    /** La URL completa, compuesta en el servidor. */
    url: string;
    /** Cómo se llama el catálogo: es lo que verá quien reciba el enlace. */
    name: string;
    visibility: CatalogVisibility;
}

/** Cuánto dura el «Copiado», en milisegundos. */
const COPIED_FOR = 2000;

export function ShareLinkField({ url, name, visibility }: ShareLinkFieldProps) {
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), COPIED_FOR);

        return () => window.clearTimeout(timer);
    }, [copied]);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
        } catch {
            // Sin permiso de portapapeles —o en un navegador que no lo
            // ofrece— el enlace sigue estando a la vista y seleccionable, que
            // es el camino de siempre. Un error rojo aquí asustaría sobre algo
            // que no ha dejado de funcionar.
            setCopied(false);
        }
    };

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                <label className="flex flex-1 flex-col gap-1">
                    <span className="fieldset-legend">Enlace del catálogo</span>

                    <input className="input w-full font-mono text-sm" value={url} readOnly onFocus={(event) => event.target.select()} />
                </label>

                <Button type="button" variant="secondary" onClick={copy}>
                    {copied ? 'Copiado' : 'Copiar enlace'}
                </Button>
            </div>

            {/* RB-6 · con esas palabras. Es la confusión que hace que alguien
                comparta lo que creía privado: `is_listed` gobierna la vitrina,
                no el acceso (RB-3). */}
            <p className="text-sm opacity-70">
                {visibility === 'listed'
                    ? 'Aparece en tu vitrina y cualquiera con el enlace puede verlo.'
                    : 'Cualquiera con el enlace puede verlo, aunque no aparezca en tu vitrina.'}
            </p>

            <figure className="border-base-300 bg-base-200 flex flex-col gap-1 rounded-lg border p-3">
                <figcaption className="text-xs font-medium opacity-70">Así se verá al compartirlo</figcaption>

                <span className="font-medium">{name}</span>
                <span className="text-xs break-all opacity-70">{url}</span>
            </figure>
        </section>
    );
}
