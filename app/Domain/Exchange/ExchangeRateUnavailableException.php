<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

/**
 * La fuente de la tasa no respondió, tardó de más o cambió de formato
 * (`REQ-FX-002` SP-1, SP-3, SP-4).
 *
 * ## Es un problema de ingesta, y por eso es reintentable
 *
 * Lo que comunica es que **no pudimos leer** la tasa, no que no la haya. El
 * histórico y la caché siguen donde estaban, el catálogo sigue proyectando con
 * la última que se persistió y nadie deja de vender por esto
 * (`REQ-FX-005` RB-1). Quien la recoge es el job `FetchExchangeRate`
 * (`T-020`), que reintenta con backoff —10 s, 60 s, 300 s— y, si los tres
 * intentos fallan, registra el error y conserva lo anterior.
 *
 * ## No es lo mismo que `MissingExchangeRateException`, aunque lo parezca
 *
 * Son dos tipos porque tienen dos dueños y dos desenlaces. Esta se queda en la
 * cola y no llega nunca al cliente; `MissingExchangeRateException` es una
 * operación de negocio que no se puede completar y acaba en un `422` delante
 * de una persona. Con una sola excepción para las dos cosas, el job reintentaría
 * un fallo que no se arregla reintentando —no hay tasa: reintentar leerla mil
 * veces no la crea— y la pantalla enseñaría «la fuente no respondió» a quien
 * lo que tiene que hacer es fijar una tasa manual.
 */
final class ExchangeRateUnavailableException extends RuntimeException
{
    /**
     * La fuente no respondió, o respondió con un error.
     */
    public static function unreachable(RateSource $source, string $reason): self
    {
        return new self(sprintf(
            'No se pudo obtener la tasa de %s: %s. Se conserva la última tasa persistida.',
            $source->value,
            $reason,
        ));
    }

    /**
     * La fuente respondió, pero no con lo que decía su contrato (SP-4).
     *
     * Tiene su propio constructor porque es el fallo que no se arregla
     * esperando: un cambio de formato del proveedor sigue igual de roto en el
     * tercer reintento, y el mensaje tiene que decir qué se esperaba para que
     * quien lea el log sepa que hay que tocar el parser.
     */
    public static function unexpectedPayload(RateSource $source, string $expected): self
    {
        return new self(sprintf(
            'La respuesta de la fuente %s no tiene la forma esperada (%s). Revisa el parser: '
            .'reintentar no lo arregla.',
            $source->value,
            $expected,
        ));
    }
}
