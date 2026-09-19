<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use RuntimeException;

/**
 * No hay ninguna tasa con la que resolver una operación que la exige
 * (`REQ-FX-005` SP-2, `REQ-FX-006` SP-1).
 *
 * ## Es un problema de negocio, y bloquea
 *
 * Casi nada exige tasa: navegar, ver una ficha y crear un apartado funcionan
 * sin ella, y la deuda siempre está en USD (`REQ-FX-005` RB-1,
 * `REQ-FX-006` RB-1). Lo que no se puede hacer a ciegas es **conciliar un pago
 * declarado en bolívares**: aprobarlo sin tasa exigiría inventarse a cuántos
 * dólares equivale, y ese número se congela para siempre en `payments`
 * (`REQ-FX-006` RB-3). De ahí el `422` con instrucción explícita en lugar de
 * un `500`: quien lo lee puede arreglarlo fijando la tasa manual en Ajustes.
 *
 * Distinta de `ExchangeRateUnavailableException`, que es «la fuente no
 * respondió» —reintentable, de la ingesta, nunca llega al cliente—. El motivo
 * de que sean dos está escrito allí.
 */
final class MissingExchangeRateException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (`REQ-FX-005` SP-2).
     *
     * `422` y no `503`: no es que el servicio esté caído, es que la operación
     * que se pide no se puede completar con lo que hay, y hay una acción
     * concreta que la desbloquea.
     */
    public const STATUS = 422;

    public const MESSAGE = 'No hay ninguna tasa de cambio disponible para conciliar este pago. '
        .'Fija la tasa manual en Ajustes → Tasa de cambio y vuelve a intentarlo.';

    /**
     * Se intentó conciliar un pago declarado en una moneda que exige conversión.
     */
    public static function whileSettling(RateSource $source): self
    {
        return new self(sprintf(
            '%s (fuente configurada: %s, sin ninguna tasa en el histórico).',
            self::MESSAGE,
            $source->value,
        ));
    }
}
