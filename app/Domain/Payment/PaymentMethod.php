<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Domain\Money\Currency;

/**
 * Las seis formas en que un negocio venezolano cobra (`REQ-ORG-007` RB-2,
 * `REQ-PAY-001`).
 *
 * **Es un enum compartido por dos hitos.** Lo usan `REQ-ORG-007` —dónde le
 * pagan al negocio, `T-015`— y `REQ-PAY-001` —el comprobante con el que el
 * cliente dice a cuál de esas cuentas pagó, en H4—. Su definición normativa,
 * con los tres métodos, está en los contratos compartidos de
 * [22-comprobantes-y-conciliacion.md].
 *
 * `T-015` escribió solo `identifierLabel()`, el único que consumía. Los otros
 * dos llegaron con `T-055`, los contratos de pago: `requiresReference()`, que
 * decide si el comprobante exige banco y referencia, y `defaultCurrency()`,
 * que devuelve un `Currency` y por eso no podía existir antes de `T-017` sin
 * fabricar una segunda fuente de verdad de la moneda.
 *
 * **Seis casos, no tres.** La línea de `T-015` del plan decía «3 métodos» y
 * era un error de redacción, ya corregido: manda el bloque de contratos de
 * [22].
 *
 * ## Por qué `identifier` es una sola columna y no cinco
 *
 * Lo que identifica una cuenta cambia por completo según el método —un
 * teléfono, veinte dígitos, un correo, un Pay ID, una dirección de calle—, y
 * la alternativa era una columna por forma con cuatro nulas en cada fila.
 * Lo que hace que una sola columna no sea un saco roto son estas dos piezas:
 * `identifierLabel()`, que dice **qué** hay dentro, y `ValidPaymentIdentifier`,
 * que comprueba que lo que hay dentro tiene esa forma.
 */
enum PaymentMethod: string
{
    /** Pago móvil interbancario: teléfono, cédula y banco. */
    case PagoMovil = 'pago_movil';

    /** Transferencia a cuenta bancaria nacional. */
    case Transferencia = 'transferencia';

    /** Zelle, contra un banco de Estados Unidos. */
    case Zelle = 'zelle';

    /** Binance Pay, transferencia interna sin comisión. */
    case BinancePay = 'binance_pay';

    /** Efectivo en dólares, entregado en mano. */
    case EfectivoUsd = 'efectivo_usd';

    /** Efectivo en bolívares, entregado en mano. */
    case EfectivoVes = 'efectivo_ves';

    /**
     * Qué contiene el `identifier` de una cuenta de cobro de este método
     * (RB-2).
     *
     * Existe para que la pantalla no diga «identificador» seis veces. La
     * diferencia no es cosmética: quien rellena el formulario tiene que saber
     * que en pago móvil va **su teléfono** y en transferencia **su número de
     * cuenta**, y quien lee la cuenta en el catálogo tiene que saber a qué
     * está mirando antes de copiarlo al banco.
     *
     * El `match` es exhaustivo y sin `default`: un método nuevo tiene que
     * decir qué se le pide en vez de heredar la etiqueta del vecino, que es
     * como se acaba pidiendo un teléfono donde hace falta un correo.
     */
    public function identifierLabel(): string
    {
        return match ($this) {
            self::PagoMovil => 'Teléfono',
            self::Transferencia => 'Número de cuenta',
            self::Zelle => 'Correo de Zelle',
            self::BinancePay => 'Pay ID o correo de Binance',

            // En efectivo no hay número que copiar: lo que el cliente necesita
            // es **dónde** entregarlo y, si hace falta, a qué hora.
            self::EfectivoUsd, self::EfectivoVes => 'Dirección de entrega',
        };
    }

    /**
     * Nombre del método tal como se le dice a una persona.
     *
     * No está en el contrato compartido de [22] y se añade por el mismo motivo
     * por el que `T-013` añadió `UsageMetric::label()`: el desplegable del
     * formulario y la ficha de la cuenta tienen que nombrar el método, y la
     * alternativa —una lista de etiquetas en el `.tsx`— sería un segundo censo
     * de los seis casos que se quedaría viejo el día que llegue el séptimo,
     * sin que nada fallara.
     */
    public function label(): string
    {
        return match ($this) {
            self::PagoMovil => 'Pago móvil',
            self::Transferencia => 'Transferencia bancaria',
            self::Zelle => 'Zelle',
            self::BinancePay => 'Binance Pay',
            self::EfectivoUsd => 'Efectivo en dólares',
            self::EfectivoVes => 'Efectivo en bolívares',
        };
    }

    /**
     * Indica si el dinero se entrega en mano.
     *
     * Los dos métodos de efectivo no tienen banco detrás ni número que copiar,
     * y esa diferencia gobierna qué se le pide al formulario. Se pregunta por
     * esto y no por `$method === EfectivoUsd || $method === EfectivoVes`
     * repartido por tres archivos, que es la forma de olvidarse de uno.
     */
    public function isCash(): bool
    {
        return $this === self::EfectivoUsd || $this === self::EfectivoVes;
    }

    /**
     * Si un comprobante de este método exige banco y referencia
     * (`REQ-PAY-001` RB-2).
     *
     * Todo lo que pasa por un banco o una plataforma deja una referencia que el
     * negocio puede buscar, y esa referencia es la mitad del criterio de
     * duplicados de `REQ-PAY-003`. El efectivo no deja nada que buscar
     * (RB-3). Los dos van **juntos**: la tabla `receipts` lleva un `CHECK` que
     * rechaza uno sin el otro (`T-054`).
     *
     * Se escribe con un `match` y no como `! isCash()`: un método nuevo tiene
     * que decidir si deja referencia, no heredarlo de una negación.
     */
    public function requiresReference(): bool
    {
        return match ($this) {
            self::PagoMovil, self::Transferencia, self::Zelle, self::BinancePay => true,
            self::EfectivoUsd, self::EfectivoVes => false,
        };
    }

    /**
     * La moneda en que suele llegar un pago por este método.
     *
     * **Sugiere, no impone** (`REQ-PAY-001` SP-10): un cliente puede pagar
     * dólares en efectivo a una cuenta de bolívares en efectivo, y el
     * formulario solo la preselecciona. Binance Pay se liquida en USDT, que el
     * negocio concilia como dólares.
     */
    public function defaultCurrency(): Currency
    {
        return match ($this) {
            self::PagoMovil, self::Transferencia, self::EfectivoVes => Currency::VES,
            self::Zelle, self::BinancePay, self::EfectivoUsd => Currency::USD,
        };
    }
}
