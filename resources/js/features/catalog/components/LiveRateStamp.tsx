import { Button } from '@/components/ui/button';
import { useLiveRate } from '@/features/catalog/hooks/useLiveRate';
import { RateStamp } from '@/features/currency/components/RateStamp';
import { type RateView } from '@/types/exchange';
import { router } from '@inertiajs/react';

/**
 * El sello de la tasa, refrescado cada cinco minutos (`REQ-FX-005` RB-2,
 * `REQ-FX-002` RB-5).
 *
 * El sello lo pinta `RateStamp`, que ya estaba escrito (`T-022`); esto solo le
 * da la tasa viva en lugar de la de cuando se abrió la pestaña.
 *
 * ## Cuando la tasa cambia, lo dice
 *
 * Los precios de la página se calcularon en el servidor con la tasa de la
 * carga, y no se recalculan aquí. Así que en cuanto el refresco trae otra, el
 * sello y las cifras hablan de tasas distintas: la página lo dice con esas
 * palabras y ofrece recargar, que vuelve a pedir los precios al servidor con la
 * tasa nueva. Es la alternativa honesta a multiplicar en el navegador —que
 * sería la cuenta en el lado equivocado— y a callarlo, que dejaría a quien
 * rehaga la cuenta con un precio que no le cuadra.
 */

interface LiveRateStampProps {
    rate: RateView;
    timezone: string;
}

export function LiveRateStamp({ rate, timezone }: LiveRateStampProps) {
    const live = useLiveRate(rate);

    return (
        <span className="flex flex-wrap items-center gap-x-2">
            <RateStamp rate={live.rate} timezone={timezone} />
            {live.differsFromPrices ? (
                <span className="text-xs" role="status">
                    Los precios se calcularon con la tasa anterior.
                    <Button variant="link" size="sm" className="px-1" onClick={() => router.reload()}>
                        Actualizar precios
                    </Button>
                </span>
            ) : null}
        </span>
    );
}
