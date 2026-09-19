<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\RateView;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La tasa vigente de una fuente pública (`REQ-FX-002` RB-5 y RB-6).
 *
 * Existe porque una pestaña abierta se queda con la tasa de cuando se cargó:
 * `@tanstack/react-query` la refresca contra esto cada cinco minutos en lugar
 * de recargar la página entera (`ASU-FX-03`).
 *
 * ## Público, y por eso recibe la fuente como parámetro
 *
 * RB-6: sin datos sensibles y **sin revelar qué organización usa cuál**. Si el
 * endpoint resolviera la fuente por sí mismo a partir del catálogo que la
 * pidió, cada respuesta diría de qué tasa se fía ese negocio, que es
 * información suya. Recibiéndola como parámetro no dice nada que no estuviera
 * ya en el sello del propio catálogo, que es público por diseño.
 *
 * ## Solo las fuentes de plataforma
 *
 * `bcv` y `parallel`, nunca `manual`. Una tasa manual es de **una**
 * organización concreta (`REQ-FX-003` RB-4), así que servirla aquí exigiría
 * decir de cuál —y eso es exactamente lo que RB-6 prohíbe—. La manual viaja
 * por las page props, donde ya hay sesión y contexto; este endpoint devuelve
 * `422` si se la piden.
 *
 * ## Nunca consulta al proveedor externo
 *
 * `REQ-FX-005` SP-4: solo lee caché y base de datos. Si una petición web
 * pudiera disparar la llamada a DolarApi, el tiempo de respuesta de esto
 * —que se invoca cada cinco minutos desde cada pestaña abierta— pasaría a
 * depender de un servicio de terceros.
 */
class ExchangeRateController extends Controller
{
    /**
     * Segundos que la respuesta se considera fresca (RB-6).
     *
     * Un quinto del intervalo de ingesta: suficiente para absorber la ráfaga
     * de pestañas que preguntan a la vez, y lo bastante corto para que una
     * tasa nueva llegue dentro del mismo ciclo de refresco.
     */
    public const CACHE_SECONDS = 60;

    public function __invoke(Request $request, ExchangeRateService $rates): JsonResponse
    {
        $validated = $request->validate([
            'source' => [
                'sometimes',
                'string',
                Rule::in(array_map(fn (RateSource $source): string => $source->value, self::publicSources())),
            ],
        ]);

        $source = isset($validated['source']) && is_string($validated['source'])
            ? RateSource::from($validated['source'])
            : RateSource::Bcv;

        $view = RateView::of($rates->forSource($source), $source);

        return response()
            ->json($view->toArray())
            ->header('Cache-Control', sprintf('public, max-age=%d', self::CACHE_SECONDS));
    }

    /**
     * Las fuentes que este endpoint puede servir.
     *
     * Se derivan del enum quitando la manual, y no se escriben a mano: con dos
     * censos, el día que aparezca una cuarta fuente pública uno de los dos se
     * quedaría corto y el síntoma sería un `422` sobre una fuente que sí
     * existe.
     *
     * @return list<RateSource>
     */
    public static function publicSources(): array
    {
        $public = [];

        foreach (RateSource::cases() as $source) {
            if ($source !== RateSource::Manual) {
                $public[] = $source;
            }
        }

        return $public;
    }
}
