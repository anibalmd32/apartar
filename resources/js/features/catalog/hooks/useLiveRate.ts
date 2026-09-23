import { type RateView } from '@/types/exchange';
import { useQuery } from '@tanstack/react-query';

/**
 * La tasa de una pestaña que se queda abierta (`REQ-FX-002` RB-5, `ASU-FX-03`).
 *
 * El catálogo es la primera página donde alguien deja una pestaña abierta con
 * un precio en bolívares delante, y sin esto el sello seguiría diciendo la
 * tasa de cuando la abrió. Cada cinco minutos se pregunta a `GET /api/tasa`
 * —el endpoint interno de `T-020`, que **nunca** llama al proveedor externo— y
 * el sello se actualiza.
 *
 * ## El sello, no los importes
 *
 * Los importes llegan formateados del servidor (`REQ-FX-001` RB-6) y se
 * reproyectan en la siguiente navegación de Inertia (`REQ-FX-004` SP-4). Aquí
 * no se multiplica nada: si alguna vez aparece una cuenta en este archivo, está
 * en el lado equivocado.
 *
 * Por eso el hook dice además **si la tasa cambió** respecto a la que calculó
 * los precios que hay en pantalla. Entre el refresco y la siguiente navegación
 * el sello y las cifras hablan de tasas distintas, y la página tiene que poder
 * decirlo en lugar de dejar que alguien multiplique a mano y no le cuadre.
 *
 * ## La tasa manual no se refresca desde aquí
 *
 * El endpoint sirve solo las fuentes de plataforma y responde `422` a `manual`
 * (`REQ-FX-002` RB-6): una tasa manual es de un negocio concreto y servirla sin
 * contexto diría de cuál. Cambia cuando el negocio la edita, y la trae la
 * siguiente navegación.
 *
 * ## Si el refresco falla, se queda la que había
 *
 * Una tasa es un dato de presentación y no puede apagar el catálogo
 * (`REQ-FX-005` RB-1): un error de red deja el sello como estaba, sin aviso.
 */

/** Cinco minutos, como fija `REQ-FX-002` RB-5. */
export const RATE_REFRESH_MS = 5 * 60 * 1000;

/** El endpoint interno de `T-020`. */
export const RATE_ENDPOINT = '/api/tasa';

async function fetchRate(source: RateView['source']): Promise<RateView> {
    const response = await fetch(`${RATE_ENDPOINT}?source=${encodeURIComponent(source)}`, {
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        throw new Error(`La tasa no se pudo refrescar (${response.status}).`);
    }

    return (await response.json()) as RateView;
}

export interface LiveRate {
    /** La tasa más reciente que se conoce: la del sello. */
    rate: RateView;
    /** `true` si ya no es la tasa con la que se calcularon los precios de la página. */
    differsFromPrices: boolean;
}

export function useLiveRate(initial: RateView): LiveRate {
    const { data } = useQuery({
        queryKey: ['rate', initial.source, initial.publishedAt],
        queryFn: () => fetchRate(initial.source),
        initialData: initial,
        enabled: initial.source !== 'manual',
        staleTime: RATE_REFRESH_MS,
        refetchInterval: RATE_REFRESH_MS,
        refetchOnWindowFocus: false,
        retry: false,
    });

    return {
        rate: data,
        differsFromPrices: data.rate !== initial.rate || data.publishedAt !== initial.publishedAt,
    };
}
