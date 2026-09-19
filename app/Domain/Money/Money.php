<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Exchange\RateSnapshot;
use LogicException;

/**
 * Un importe, en la unidad mínima de su moneda (`REQ-FX-001`).
 *
 * **Es el tipo del que depende todo el dinero del sistema.** Cada precio, cada
 * cuota, cada abono, cada saldo y cada factura pasan por aquí, y por eso la
 * regla que lo gobierna es una sola: **enteros de centavos, nunca coma
 * flotante** (RB-1). No hay un `float` en ninguna operación de esta clase, ni
 * un `(float)` para salir de un apuro; la única aritmética no entera del
 * sistema es la conversión a bolívares, que va por `bcmul` a escala 8.
 *
 * ## Con signo, y a propósito
 *
 * `subtract()` **puede** devolver un importe negativo (SP-3), porque existe el
 * concepto de crédito: un cliente que paga de más tiene saldo a favor. Quien
 * prohíbe los negativos son las reglas de `LAY` y `PAY` que usen el resultado,
 * no el tipo. Es la misma decisión que en el esquema, donde los importes son
 * `BIGINT` **con signo**: restar dos `BIGINT UNSIGNED` en MySQL no da
 * negativo, desborda a un número enorme, y con dinero eso es un balance
 * corrupto en lugar de un error visible ([05 §4]).
 *
 * ## Inmutable
 *
 * Toda operación devuelve una instancia nueva (RB-2). Un `Money` que se
 * pudiera mutar convertiría cualquier referencia compartida —el total de un
 * apartado pasado a dos colaboradores— en una fuente de errores que no se
 * reproducen.
 *
 * ## Límite
 *
 * `cents` es un `int` de PHP, de 64 bits: el techo está muy por encima de lo
 * que `BIGINT` guarda y de cualquier importe real. SP-5 lo da por imposible en
 * la práctica y lo acota donde toca, en la validación del CRUD (precio máximo
 * $1.000.000), no aquí: una guarda en el constructor rechazaría sumas
 * intermedias legítimas de un cronograma largo.
 *
 * ## Lo que falta y quién lo escribe
 *
 * Esta es la Fase A de `REQ-FX-001`. Las operaciones donde vive el riesgo real
 * —el reparto con residuo, la conversión con `bcmul` y el parseo de un
 * decimal— quedan declaradas con su firma y **lanzan** nombrando a `T-018`,
 * que es quien las escribe con su batería de 500 casos. Un `allocateEvenly()`
 * que devolviera `[]` compilaría, pasaría por delante de todo y reaparecería
 * como un cronograma vacío tres hitos después.
 */
final readonly class Money
{
    public function __construct(public int $cents, public Currency $currency = Currency::USD) {}

    /**
     * Construye un importe a partir de un decimal escrito como cadena.
     *
     * Entra por cadena y no por `float` por el motivo de siempre: `"19.99"`
     * como `float` ya no vale 19,99 antes de que nadie lo multiplique.
     *
     * @throws LogicException mientras `T-018` no escriba el parseo
     */
    public static function fromDecimalString(string $amount, Currency $c = Currency::USD): self
    {
        throw new LogicException(sprintf(
            'Money::fromDecimalString() está declarado y sin implementar: es de `T-018`, la fase de lógica de '
            .'`REQ-FX-001`. Tiene que parsear "%s" a centavos de %s sin pasar por float —dos decimales, signo, '
            .'separadores y cadena vacía— y rechazar lo que no sea un decimal. Hasta entonces, construye el '
            .'importe con el constructor, que ya recibe los céntimos.',
            $amount,
            $c->value,
        ));
    }

    /**
     * Suma dos importes de la misma moneda.
     *
     * @throws CurrencyMismatchException si las monedas no coinciden (RB-3)
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other, 'sumar');

        return new self($this->cents + $other->cents, $this->currency);
    }

    /**
     * Resta otro importe de este.
     *
     * **Puede dar negativo y está permitido** (SP-3): es el crédito de quien
     * pagó de más. Quien no lo admita tiene que rechazarlo en su propia regla,
     * porque aquí no hay forma de distinguir un saldo a favor legítimo de una
     * resta equivocada.
     *
     * @throws CurrencyMismatchException si las monedas no coinciden (RB-3)
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other, 'restar');

        return new self($this->cents - $other->cents, $this->currency);
    }

    /**
     * Multiplica el importe por un número entero de veces.
     *
     * El factor es `int` y no `float` a propósito: multiplicar dinero por un
     * decimal es aplicar un porcentaje —un descuento, un interés—, y eso está
     * explícitamente fuera del alcance de `REQ-FX-001`. Lo que esto resuelve
     * es «tres cuotas de esto», que es entero por construcción.
     */
    public function multiply(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    /**
     * Indica si el importe es negativo, es decir, si es un crédito.
     */
    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * Indica si lo que queda se da por saldado
     * (`settlement_tolerance_cents`, `REQ-ORG-006`).
     *
     * **Es `<=` y no `abs() <=`, y la diferencia importa.** Un saldo pendiente
     * de 30 céntimos con tolerancia 50 está saldado; uno de **-500** —el
     * cliente pagó cinco dólares de más— también lo está, porque lo que esta
     * pregunta decide es si se puede cerrar el apartado, y haber pagado de
     * sobra no es una razón para dejarlo abierto. Con `abs()`, ese cliente se
     * quedaría con un apartado vivo por exceso de pago; qué hacer con el
     * crédito es otra decisión, de `PAY`, y no de esta comparación.
     *
     * La tolerancia se expresa en la unidad mínima de **esta misma moneda**:
     * aquí no se convierte nada.
     */
    public function isZeroOrBelowTolerance(int $toleranceCents): bool
    {
        return $this->cents <= $toleranceCents;
    }

    /**
     * Reparte el importe en $parts partes iguales, acumulando el residuo
     * de la división entera en la ÚLTIMA parte.
     *
     * La invariante es `sum(partes) === cents` para cualquier `cents` y
     * cualquier `parts >= 1` (RB-4): `$100` en 3 son `[3333, 3333, 3334]` y no
     * `[3333, 3333, 3333]`, porque el céntimo que falta es dinero de alguien.
     * `parts` menor que 1 es `InvalidArgumentException` (SP-1).
     *
     * @return list<self>
     *
     * @throws LogicException mientras `T-018` no escriba el reparto
     */
    public function allocateEvenly(int $parts): array
    {
        throw new LogicException(sprintf(
            'Money::allocateEvenly() está declarado y sin implementar: es de `T-018`, que lo cierra con la '
            .'propiedad `sum(partes) === cents` sobre 500 casos aleatorios (`REQ-FX-001` RB-4). Se pidió repartir '
            .'%d céntimos en %d partes. Devolver un reparto aproximado aquí sería peor que no devolver nada: un '
            .'cronograma que no suma el total no se nota hasta que un cliente termina de pagar y sigue debiendo '
            .'un céntimo.',
            $this->cents,
            $parts,
        ));
    }

    /**
     * Convierte el importe a la moneda de destino con una tasa concreta.
     *
     * La tasa viaja como `RateSnapshot` y no como número suelto porque la
     * conversión tiene que quedar **con su procedencia y su fecha**: es lo que
     * `REQ-FX-006` congela en `payments` para que el balance de un cliente no
     * cambie cuando la tasa suba. `bcmul` a escala 8 y *half-up* a dos
     * decimales **al final**, nunca en pasos intermedios (RB-5); tasa `0` o
     * negativa es `InvalidRateException` **antes** de convertir (SP-4).
     *
     * @throws LogicException mientras `T-018` no escriba la conversión
     */
    public function convertTo(Currency $target, RateSnapshot $rate): self
    {
        throw new LogicException(sprintf(
            'Money::convertTo() está declarado y sin implementar: es de `T-018`, la fase de lógica de '
            .'`REQ-FX-001`. Se pidió convertir %d céntimos de %s a %s con la tasa %s (%s). Tiene que usar bcmul a '
            .'escala 8 y redondear half-up a dos decimales solo al final (RB-5), y lanzar InvalidRateException si '
            .'la tasa no es positiva (SP-4). Ningún otro módulo puede implementar la conversión por su cuenta.',
            $this->cents,
            $this->currency->value,
            $target->value,
            $rate->rate,
            $rate->source->value,
        ));
    }

    /**
     * Guarda de RB-3, compartida por las operaciones entre dos importes.
     *
     * @throws CurrencyMismatchException
     */
    private function assertSameCurrency(self $other, string $operation): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency, $operation);
        }
    }
}
