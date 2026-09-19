<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Lee las cotizaciones públicas de DolarApi ([05 §6], `REQ-FX-002`).
 *
 * ## Una sola petición para las dos fuentes, y cómo encaja con la interfaz
 *
 * `GET /v1/dolares` devuelve **todas** las cotizaciones en una respuesta, y
 * RB-0 exige que la ingesta de toda la plataforma sea una sola llamada. Pero
 * `ExchangeRateProvider::fetch()` pregunta por **una** fuente, porque así lo
 * fija el contrato compartido del módulo 13.
 *
 * Las dos cosas se concilian memorizando la respuesta: la primera llamada a
 * `fetch()` trae el payload entero y las siguientes lo leen de memoria. Dos
 * fuentes, una petición. Es lo que comprueba el test que cuenta peticiones con
 * N organizaciones sembradas.
 *
 * **Por eso el binding no es singleton** (`ExchangeServiceProvider`): el memo
 * vive lo que vive la instancia, y el worker es un proceso de vida larga que
 * despacha el job cada treinta minutos. Con una instancia compartida, la
 * segunda ejecución serviría la respuesta de la primera y la tasa se quedaría
 * congelada para siempre sin que nada fallara. Cada ejecución del job resuelve
 * su proveedor y se lo queda para esa vuelta.
 *
 * ## Lo que llega de fuera es sospechoso
 *
 * Aquí se comprueba **la forma** —que la respuesta sea una lista de objetos
 * con los campos que el contrato declara— y se lanza
 * `ExchangeRateUnavailableException` en cuanto algo no encaja, que es lo que
 * convierte un cambio silencioso del proveedor en un job rojo en vez de en una
 * tasa `null` en producción (SP-4). Lo que **no** se comprueba aquí es si la
 * cifra es creíble: eso es plausibilidad y la decide `FetchExchangeRate`
 * comparando con el histórico, porque necesita saber qué había antes.
 */
final class DolarApiProvider implements ExchangeRateProvider
{
    /**
     * Cómo nombra DolarApi a cada una de nuestras fuentes ([05 §6.2]).
     *
     * Las demás cotizaciones de la respuesta se ignoran en silencio: el
     * proveedor publica varias y solo dos son nuestras.
     */
    private const FEEDS = [
        'oficial' => RateSource::Bcv->value,
        'paralelo' => RateSource::Parallel->value,
    ];

    /**
     * Cotizaciones ya leídas en esta instancia, indexadas por fuente nuestra.
     *
     * @var array<string, RateSnapshot>|null
     */
    private ?array $quotes = null;

    /**
     * Por qué se descartó cada cotización que venía y no se pudo usar.
     *
     * Se guarda para que el error de `fetch()` diga **qué** le pasaba a esa
     * cotización en vez de limitarse a decir que falta: «falta la oficial» y
     * «la oficial venía con los tres campos a cero» mandan a mirar sitios
     * distintos.
     *
     * @var array<string, string>
     */
    private array $discarded = [];

    /**
     * @param  list<string>  $fieldPriority  orden en que se busca la cifra ([05 §6.2])
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly int $timeoutSeconds,
        private readonly array $fieldPriority,
    ) {}

    /**
     * Cotización vigente de esa fuente, según DolarApi.
     *
     * @throws ExchangeRateUnavailableException si la fuente no responde, tarda de más o cambia de formato
     */
    public function fetch(RateSource $source): RateSnapshot
    {
        if (! $this->supports($source)) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'DolarApi solo publica cotizaciones de plataforma y la tasa manual es un dato de cada negocio',
            );
        }

        $quotes = $this->quotes ??= $this->readAllQuotes();

        return $quotes[$source->value] ?? throw ExchangeRateUnavailableException::unexpectedPayload(
            $source,
            $this->discarded[$source->value] ?? sprintf(
                'falta la cotización "%s" en la respuesta',
                array_search($source->value, self::FEEDS, true),
            ),
        );
    }

    /**
     * DolarApi publica las dos fuentes de plataforma, y ninguna manual.
     *
     * La manual no está en ninguna API porque no es de nadie más que del
     * negocio que la escribió: la resuelve `ManualRateProvider`.
     */
    public function supports(RateSource $source): bool
    {
        return in_array($source->value, self::FEEDS, true);
    }

    /**
     * Trae el payload entero y lo convierte en cotizaciones nuestras.
     *
     * **Una cotización que no se puede usar se descarta, no tumba las demás**
     * ([05 §6.2]). Es la diferencia entre perder una fuente y perder las dos:
     * si el proveedor publica el paralelo con los tres campos a cero, el BCV
     * sigue siendo perfectamente utilizable y no hay ninguna razón para que el
     * catálogo se quede sin él. Quien pida la fuente descartada recibe el
     * motivo; el job lo trata como cualquier otro fallo de esa fuente.
     *
     * @return array<string, RateSnapshot>
     *
     * @throws ExchangeRateUnavailableException si no se pudo usar ninguna
     */
    private function readAllQuotes(): array
    {
        $payload = $this->request();
        $fetchedAt = CarbonImmutable::now();

        $quotes = [];

        foreach ($payload as $entry) {
            if (! is_array($entry) || ! is_string($entry['fuente'] ?? null)) {
                continue;
            }

            $source = self::FEEDS[$entry['fuente']] ?? null;

            if ($source === null) {
                // Otras cotizaciones del proveedor: se ignoran en silencio
                // ([05 §6.2]). No son un error de formato, son fuentes que no
                // usamos.
                continue;
            }

            try {
                $quotes[$source] = $this->snapshotOf(RateSource::from($source), $entry, $fetchedAt);
            } catch (ExchangeRateUnavailableException $exception) {
                $this->discarded[$source] = $exception->getMessage();
            }
        }

        if ($quotes === []) {
            throw ExchangeRateUnavailableException::unexpectedPayload(
                RateSource::Bcv,
                $this->discarded === []
                    ? 'ninguna cotización con una `fuente` reconocible (se esperaban "oficial" y "paralelo")'
                    : 'ninguna cotización utilizable: '.implode(' · ', $this->discarded),
            );
        }

        return $quotes;
    }

    /**
     * La petición, con su timeout y sus fallos traducidos.
     *
     * El timeout es de 8 s (SP-3) porque esto corre en la cola `high`: una
     * petición colgada bloquearía al worker detrás de un servicio que no
     * controlamos. Los reintentos **no** se hacen aquí —son del job, con su
     * backoff de 10/60/300— para que un fallo de red y un cambio de formato
     * se traten igual desde fuera y solo uno de los dos tenga sentido
     * reintentar.
     *
     * @return list<mixed>
     *
     * @throws ExchangeRateUnavailableException
     */
    private function request(): array
    {
        try {
            $response = $this->http->timeout($this->timeoutSeconds)->acceptJson()->get($this->url);
        } catch (Throwable $exception) {
            // `Throwable` y no solo `ConnectionException`: desde aquí, un
            // timeout, un DNS que no resuelve y un fallo de TLS son el mismo
            // caso —no pudimos leer la tasa— y se tratan igual (SP-1, SP-3).
            // Acotar la captura dejaría que una excepción del cliente HTTP
            // subiera cruda hasta la cola, donde nadie la relaciona con la
            // tasa.
            throw ExchangeRateUnavailableException::unreachable(RateSource::Bcv, $exception->getMessage());
        }

        if (! $response->successful()) {
            throw ExchangeRateUnavailableException::unreachable(
                RateSource::Bcv,
                sprintf('respondió %d', $response->status()),
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw ExchangeRateUnavailableException::unexpectedPayload(
                RateSource::Bcv,
                'se esperaba una lista JSON de cotizaciones',
            );
        }

        return $payload;
    }

    /**
     * Convierte una cotización del proveedor en un `RateSnapshot` nuestro.
     *
     * @param  array<array-key, mixed>  $entry
     *
     * @throws ExchangeRateUnavailableException
     */
    private function snapshotOf(RateSource $source, array $entry, CarbonImmutable $fetchedAt): RateSnapshot
    {
        return new RateSnapshot(
            rate: $this->rateOf($source, $entry),
            source: $source,
            publishedAt: $this->publishedAtOf($source, $entry),
            fetchedAt: $fetchedAt,
        );
    }

    /**
     * La cifra: **el primer campo mayor que cero** en el orden configurado
     * ([05 §6.2]).
     *
     * DolarApi devuelve `0` en los campos que esa fuente no publica —el BCV no
     * tiene compra ni venta, solo promedio—, así que un `0` no es un precio de
     * cero: es un hueco. Si los tres están a cero o ausentes, la cotización se
     * descarta.
     *
     * Se devuelve como **cadena decimal**, sin pasar por `float`: es la misma
     * regla que hace que el dinero vaya en céntimos enteros (`REQ-FX-001`
     * RB-1), y el sitio por el que la coma flotante entraría es exactamente
     * este.
     *
     * @param  array<array-key, mixed>  $entry
     *
     * @throws ExchangeRateUnavailableException
     */
    private function rateOf(RateSource $source, array $entry): string
    {
        foreach ($this->fieldPriority as $field) {
            $value = $entry[$field] ?? null;

            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                continue;
            }

            $rate = $this->decimalOf($value);

            if ($rate !== null && bccomp($rate, '0', 8) > 0) {
                return $rate;
            }
        }

        throw ExchangeRateUnavailableException::unexpectedPayload(
            $source,
            sprintf('ningún valor mayor que cero en %s', implode(', ', $this->fieldPriority)),
        );
    }

    /**
     * Normaliza a cadena decimal el número que venga en el JSON.
     *
     * `json_decode` entrega los números como `int` o `float` y ahí no hay nada
     * que hacer —el formato de intercambio es el que es—, así que la conversión
     * a cadena ocurre **una sola vez, aquí**, con la escala de la columna. A
     * partir de este punto la tasa es una cadena en todo el sistema.
     */
    private function decimalOf(int|float|string $value): ?string
    {
        $decimal = is_string($value) ? trim($value) : sprintf('%.8F', $value);

        return preg_match('/^-?(\d+(\.\d+)?|\.\d+)$/', $decimal) === 1 ? $decimal : null;
    }

    /**
     * `fechaActualizacion` es `published_at`: cuándo lo publicó la fuente.
     *
     * Es la fecha que se le enseña al negocio y la que mide la obsolescencia
     * (RB-3b), así que una respuesta sin ella no sirve aunque traiga la cifra:
     * no se puede decir «Tasa BCV del …» sobre un dato sin fecha.
     *
     * ## Se convierte a la zona de la aplicación aquí, y no más adelante
     *
     * DolarApi publica en UTC (`…T14:30:00.000Z`) y el resto del sistema
     * trabaja en `APP_TIMEZONE`. Laravel escribe una columna `datetime` con el
     * reloj de pared **de la propia fecha**, sin convertirla, así que una fecha
     * en UTC se guardaría como si fuese local y volvería de la base de datos
     * desplazada cuatro horas.
     *
     * El daño no es solo que la fecha esté mal: la deduplicación de RB-3
     * compara `published_at` con lo persistido y **nunca volvería a
     * coincidir**, de modo que cada vuelta del job insertaría una fila nueva
     * de la misma cotización. Se normaliza en el borde, que es donde entra el
     * dato de fuera, para que a partir de aquí todo el sistema hable la misma
     * hora. El instante no cambia: solo cómo se escribe.
     *
     * @param  array<array-key, mixed>  $entry
     *
     * @throws ExchangeRateUnavailableException
     */
    private function publishedAtOf(RateSource $source, array $entry): CarbonImmutable
    {
        $published = $entry['fechaActualizacion'] ?? null;

        if (! is_string($published) || $published === '') {
            throw ExchangeRateUnavailableException::unexpectedPayload($source, 'falta `fechaActualizacion`');
        }

        try {
            return CarbonImmutable::parse($published)->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            throw ExchangeRateUnavailableException::unexpectedPayload(
                $source,
                sprintf('`fechaActualizacion` no es una fecha ("%s")', $published),
            );
        }
    }
}
