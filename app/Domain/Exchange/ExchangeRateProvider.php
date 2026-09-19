<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

/**
 * Contrato de obtención de la tasa (contratos compartidos del módulo 13,
 * `REQ-FX-003`).
 *
 * **Toda fuente externa se implementa detrás de esta interfaz**, y el binding
 * se resuelve desde `config/services.php → exchange_rate.driver` en un
 * `ServiceProvider` (`REQ-FX-003` RB-5): añadir un proveedor nuevo no obliga a
 * tocar nada más. Sus dos implementaciones llegan con `T-020`
 * —`DolarApiProvider`, que consulta la API pública, y `ManualRateProvider`,
 * que lee la tasa que fijó el negocio—.
 *
 * `supports()` existe porque las fuentes no son intercambiables entre sí:
 * `DolarApiProvider` sabe de `bcv` y `parallel` y no sabe nada de `manual`,
 * que no está en ninguna API porque no es de nadie más que del negocio que la
 * escribió. Preguntar antes de pedir es lo que evita que esa asimetría se
 * resuelva con una excepción en tiempo de ejecución.
 */
interface ExchangeRateProvider
{
    /**
     * Tasa vigente de esa fuente, según quien la publique.
     *
     * Lanza y **no** devuelve `null` cuando la fuente no responde: son cosas
     * distintas y `T-020` las trata distinto. «La fuente falló» es
     * reintentable con backoff y conserva lo que ya había
     * (`REQ-FX-002` SP-1); «no hay tasa ninguna» es
     * `MissingExchangeRateException` y la decide el servicio, no el provider.
     *
     * @throws ExchangeRateUnavailableException si la fuente no responde, tarda de más o cambia de formato
     */
    public function fetch(RateSource $source): RateSnapshot;

    /**
     * Indica si este proveedor sabe resolver esa fuente.
     */
    public function supports(RateSource $source): bool;
}
