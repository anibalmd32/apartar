<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Domain\Exchange\RateSnapshot;
use InvalidArgumentException;

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
 * ## Dónde está la única aritmética que no es entera
 *
 * En `convertTo()`, y va por `bcmath` sobre cadenas. Todo lo demás —sumar,
 * restar, multiplicar por un entero, repartir— es aritmética de enteros de
 * PHP, exacta por construcción. Que la conversión sea el único sitio con
 * decimales es lo que permite auditarla entera de un vistazo: si el dinero
 * alguna vez pierde un céntimo, o está aquí o está en el reparto.
 */
final readonly class Money
{
    /**
     * Escala de trabajo de la conversión (RB-5).
     *
     * Ocho decimales es lo que declara `exchange_rates.rate DECIMAL(18,8)`:
     * se opera con toda la precisión de la tasa y se redondea **una sola vez**,
     * al final.
     */
    private const RATE_SCALE = 8;

    /**
     * Un decimal que `bcmath` sabe leer y que no es ambiguo.
     *
     * Ni notación exponencial —`bcmul` la rechaza con un `ValueError`— ni
     * separadores de millar: `"1.234"` significa mil doscientos treinta y
     * cuatro en la convención venezolana y uno coma doscientos treinta y
     * cuatro en la nuestra, y adivinar cuál de las dos quiso decir quien la
     * escribió es exactamente lo que no puede hacer un tipo monetario.
     */
    private const DECIMAL_PATTERN = '/^-?(\d+(\.\d{1,2})?|\.\d{1,2})$/';

    /**
     * Una tasa tal como puede venir de `RateSnapshot::rate`.
     *
     * Más permisivo que `DECIMAL_PATTERN` en los decimales —la tasa tiene
     * ocho, no dos— y igual de estricto en todo lo demás.
     */
    private const RATE_PATTERN = '/^-?(\d+(\.\d+)?|\.\d+)$/';

    public function __construct(public int $cents, public Currency $currency = Currency::USD) {}

    /**
     * Construye un importe a partir de un decimal escrito como cadena.
     *
     * Entra por cadena y no por `float` por el motivo de siempre: `"19.99"`
     * como `float` ya no vale 19,99 antes de que nadie lo multiplique.
     *
     * ## Qué acepta, y por qué tan poco
     *
     * Solo un decimal llano: signo negativo opcional, dígitos, y como mucho
     * **dos** decimales —`"19.99"`, `"-0.50"`, `".99"`, `"1200"`—. Todo lo
     * demás se rechaza, y cada rechazo tiene su motivo:
     *
     *  - **Separadores de millar, no.** `"1.234"` son mil doscientos treinta y
     *    cuatro para quien escribe en la convención local y uno con pico para
     *    quien escribe en la nuestra. Aceptarlo obligaría a adivinar, y
     *    adivinar aquí es equivocarse por un factor de mil.
     *  - **Más de dos decimales, tampoco.** `"19.999"` no es un importe: es un
     *    número al que alguien todavía tiene que decidir cómo redondear, y esa
     *    decisión no la puede tomar un constructor en silencio.
     *  - **Notación exponencial, nunca.** `bcmath` la rechaza de todos modos, y
     *    `"1e3"` en un formulario de precios es un error de quien lo envió.
     *  - **Espacios alrededor, tampoco.** Recortarlos escondería que alguien
     *    está pasando entrada sin normalizar; Laravel ya recorta la petición
     *    antes de que esto se llame.
     *
     * La frontera es estricta a propósito: lo que no se entiende se para aquí,
     * y no tres capas más abajo convertido en un precio plausible.
     *
     * @throws InvalidArgumentException si no es un decimal de dos cifras
     */
    public static function fromDecimalString(string $amount, Currency $c = Currency::USD): self
    {
        if (preg_match(self::DECIMAL_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El importe "%s" no es un decimal de hasta dos cifras. Se admite un signo negativo, dígitos y '
                .'como mucho dos decimales ("19.99", "-0.50", ".99", "1200"): ni separadores de millar —"1.234" '
                .'es ambiguo entre mil doscientos treinta y cuatro y uno con pico—, ni notación exponencial, ni '
                .'espacios alrededor. Si vienen más decimales, decide tú cómo redondearlos antes de llamar.',
                $amount,
            ));
        }

        // `bcmul` a escala 0 mueve la coma dos posiciones y trunca lo que ya
        // sabemos que no existe: el patrón garantiza que no hay un tercer
        // decimal que perder. Se hace con bcmath y no con `(int) ($amount *
        // 100)` porque ese float es justo el redondeo que todo esto evita.
        return new self((int) bcmul($amount, '100', 0), $c);
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
     * ## Por qué la última parte se calcula restando
     *
     * La invariante no se comprueba después: **se construye**. Las `n-1`
     * primeras partes son la división entera, y la última es *lo que queda*
     * —`cents - base * (n-1)`—, no `base + residuo`. Las dos fórmulas dan el
     * mismo número, pero la primera lo garantiza aunque alguien se equivoque
     * al calcular el residuo, y con dinero la diferencia entre «cuadra» y
     * «cuadra siempre» es la única que importa.
     *
     * Con importes negativos funciona igual, y es intencionado: `intdiv()`
     * trunca hacia cero, así que `-10000` en tres partes son
     * `[-3333, -3333, -3334]` y siguen sumando exactamente `-10000`. Un
     * crédito se reparte como se reparte una deuda (SP-3).
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException si `$parts` es menor que 1 (SP-1)
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException(sprintf(
                'No se puede repartir un importe en %d partes: hacen falta al menos 1. Un reparto en cero partes '
                .'devolvería una lista vacía que suma cero y perdería los %d céntimos por el camino.',
                $parts,
                $this->cents,
            ));
        }

        $base = intdiv($this->cents, $parts);

        $allocated = [];

        for ($part = 1; $part < $parts; $part++) {
            $allocated[] = new self($base, $this->currency);
        }

        // Lo que queda, y no «la base más el residuo»: así la suma es exacta
        // por construcción para cualquier `cents` y cualquier `parts`.
        $allocated[] = new self($this->cents - $base * ($parts - 1), $this->currency);

        return $allocated;
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
     * ## Una sola operación, un solo redondeo
     *
     * La tasa está en bolívares por dólar, y los dos importes se llevan en
     * céntimos, así que la conversión entera es **una multiplicación**:
     * `2000 céntimos × 42.50 = 85000 céntimos` de bolívar. No hay que dividir
     * entre cien para pasar a unidades ni volver a multiplicar después, y esa
     * ausencia es la que garantiza RB-5 por construcción: no existe un paso
     * intermedio en el que se pueda redondear, porque no hay pasos
     * intermedios. Redondear a céntimo entero es, en unidades, redondear a dos
     * decimales.
     *
     * A la inversa —un pago declarado en bolívares que hay que conciliar en
     * dólares, `REQ-FX-006` RB-2— la operación es la división recíproca, con
     * la misma escala y el mismo redondeo único.
     *
     * ## Convertir a la misma moneda no mira la tasa
     *
     * Y es deliberado: `REQ-FX-005` RB-1 dice que **nunca** se bloquea la
     * navegación por la tasa. Con una fila corrupta en el histórico, pintar un
     * precio en dólares tiene que seguir funcionando; validar antes de
     * comprobar si hay algo que convertir apagaría el catálogo entero por un
     * dato que esa página no usa.
     *
     * @throws InvalidRateException si la tasa no es un decimal mayor que cero (SP-4)
     */
    public function convertTo(Currency $target, RateSnapshot $rate): self
    {
        if ($target === $this->currency) {
            return new self($this->cents, $this->currency);
        }

        $multiplier = self::assertUsableRate($rate->rate);

        $converted = match ($this->currency) {
            Currency::USD => bcmul((string) $this->cents, $multiplier, self::RATE_SCALE),
            Currency::VES => bcdiv((string) $this->cents, $multiplier, self::RATE_SCALE),
        };

        return new self((int) self::roundHalfUp($converted), $target);
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

    /**
     * Devuelve la tasa si se puede convertir con ella, y lanza si no (SP-4).
     *
     * Comprueba la forma **antes** que el valor porque `bcmath` no perdona: en
     * PHP 8, `bcmul("cuarenta", …)` es un `ValueError` con un mensaje que
     * habla de argumentos y no de tasas, y quien lo lea en un log a las tres
     * de la mañana no sabrá que lo que hay que mirar es el parser de la
     * fuente.
     *
     * Es pública desde `T-055`: la tasa que el negocio escribe a mano al
     * conciliar (`ManualRate`) tiene que pasar exactamente esta comprobación,
     * y no una copia de ella.
     *
     * @throws InvalidRateException
     */
    public static function assertUsableRate(string $rate): string
    {
        if (preg_match(self::RATE_PATTERN, $rate) !== 1) {
            throw InvalidRateException::malformed($rate);
        }

        if (bccomp($rate, '0', self::RATE_SCALE) <= 0) {
            throw InvalidRateException::notPositive($rate);
        }

        return $rate;
    }

    /**
     * Redondea un decimal a entero, **half-up**, sin pasar por `float`.
     *
     * Sumar medio y truncar es el redondeo *half away from zero*: `2.5` da `3`
     * y `-2.5` da `-3`. Es el que aplica `round()` de PHP y el que usa la
     * librería monetaria que respalda el cast de Eloquent, y para dinero es el
     * único simétrico —convertir un crédito de cinco dólares tiene que dar
     * exactamente lo contrario que convertir un cobro de cinco dólares, o el
     * saldo de quien pagó de más dependería del signo—.
     *
     * `bcadd` con escala 0 trunca hacia cero después de sumar, que es
     * justamente lo que hace falta; `round()` no sirve porque recibiría un
     * `float`.
     */
    private static function roundHalfUp(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.5' : '0.5', 0);
    }
}
