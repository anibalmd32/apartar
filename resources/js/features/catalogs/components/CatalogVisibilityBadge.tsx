import { Badge, type BadgeTone } from '@/components/ui/badge';
import { type CatalogVisibility } from '@/types/catalog';

/**
 * El estado del catálogo en una etiqueta (`REQ-BOF-024` RB-5).
 *
 * Los cuatro estados en un solo sitio porque se pintan en dos —el listado y la
 * cabecera del formulario— y dos tablas de rótulos coinciden hasta el día en
 * que una cambia. Es el mismo motivo por el que los estados del producto
 * vienen del enum del servidor y no de una lista en el `.tsx`.
 *
 * El color **nunca** distingue por sí solo: cada estado lleva su palabra, como
 * el resto de los distintivos del proyecto.
 *
 * `Unlisted` es `info` y no `warning` a propósito: no es un estado
 * defectuoso, es el modo con el que se reparte una campaña por WhatsApp
 * (RB-3). `Expired` sí avisa, porque es el único en que el enlace que el
 * negocio difundió ha dejado de vender.
 */

const LABELS: Record<CatalogVisibility, string> = {
    draft: 'Borrador',
    listed: 'En tu vitrina',
    unlisted: 'Solo por enlace',
    expired: 'Caducado',
};

const TONES: Record<CatalogVisibility, BadgeTone> = {
    draft: 'neutral',
    listed: 'success',
    unlisted: 'info',
    expired: 'warning',
};

export function CatalogVisibilityBadge({ visibility }: { visibility: CatalogVisibility }) {
    return <Badge tone={TONES[visibility]}>{LABELS[visibility]}</Badge>;
}
