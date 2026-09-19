import { publishedStamp } from '@/features/currency/lib/rateAge';
import { type RateView } from '@/types/exchange';

/**
 * «Tasa BCV del 18/09 09:00» (`REQ-FX-005` RB-2).
 *
 * Es lo que convierte un precio en bolívares en un precio **verificable**:
 * quien lo lee sabe con qué tasa se calculó y de cuándo es, y puede rehacer la
 * cuenta. Sin el sello, una cifra proyectada es una cifra que hay que creerse.
 *
 * ## La fecha es la de publicación, y el nombre viene del servidor
 *
 * `publishedAt` dice cuándo lo publicó la fuente, no cuándo corrió nuestra
 * ingesta — con la otra, un fin de semana sin publicación estamparía «hoy
 * 09:00» sobre una tasa del viernes—. Y `sourceLabel` llega resuelto desde
 * `RateSource::label()`: con un diccionario escrito aquí, esta pantalla y el
 * selector de ajustes podrían llamar cosas distintas a la misma tasa.
 *
 * ## El aviso de antigüedad **no bloquea nada** (RB-3)
 *
 * Con más de 24 h se añade una advertencia discreta y el importe se sigue
 * mostrando con esa misma tasa. Dejar de proyectar por antigüedad violaría
 * RB-1, que es la regla que sostiene el requisito entero: la tienda sigue
 * vendiendo aunque la fuente falle.
 *
 * ## Sin tasa no hay sello
 *
 * No se pinta «sin tasa» donde va una fecha: no hay nada que sellar. Quien
 * explica la ausencia es el toggle deshabilitado con su tooltip, que es donde
 * la persona va a buscar por qué no ve bolívares.
 */

interface RateStampProps {
    rate: RateView;
    /** La zona del negocio, que llega en las page props. */
    timezone: string;
    className?: string;
}

export function RateStamp({ rate, timezone, className }: RateStampProps) {
    const stamp = publishedStamp(rate.publishedAt, timezone);

    if (rate.rate === null || stamp === null) {
        return null;
    }

    return (
        <span className={`text-xs opacity-70 ${className ?? ''}`}>
            Tasa {rate.sourceLabel} del {stamp}
            {rate.isStale ? <span className="ml-1 opacity-100"> · puede estar desactualizada</span> : null}
        </span>
    );
}
