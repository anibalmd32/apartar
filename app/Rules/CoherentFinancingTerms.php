<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Layaway\ScheduleGenerator;
use App\Domain\Money\Money;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/**
 * Las seis reglas cruzadas que hacen que un producto se pueda financiar
 * (`REQ-BOF-020` RB-1).
 *
 * No son seis validaciones sueltas: son **un contrato**, y cada una impide un
 * producto distinto que el sistema aceptaría y luego no sabría cobrar. El
 * síntoma de relajar cualquiera no aparece aquí, aparece en H3 cuando
 * `ScheduleGenerator` intente convertir esas cifras en un cronograma.
 *
 *  - **`down_payment` estrictamente menor que `price`** (SP-1): si fueran
 *    iguales no hay nada que financiar, y el apartado sería una compra al
 *    contado disfrazada de plan de pago. Por encima, las cuotas salen
 *    negativas.
 *  - **`compare_at_price` mayor que `price`** (SP-2): es el precio tachado.
 *    Menor o igual convierte una oferta en un engaño, y uno visible en la
 *    pantalla del cliente.
 *  - **`installments_count` entre 1 y 36** y **`frequency_days` entre 1 y
 *    90**: los dos extremos del cronograma que `ScheduleGenerator` recorrerá.
 *  - **`price` mayor que cero y hasta un millón** y **`stock` no negativo**:
 *    los límites que hacen que el resto de las cuentas quepan donde tienen que
 *    caber.
 *
 * ## Por qué cada error aterriza en **su** campo
 *
 * Una regla de Laravel solo puede hablar del atributo al que se ata, y estas
 * seis hablan de cinco campos distintos. Devolver los seis mensajes sobre
 * `price` pondría «la inicial no puede superar al precio» encima del precio,
 * que es el campo correcto en cero de los dos casos. Por eso la regla es
 * además `ValidatorAwareRule` y escribe en el bolsín de errores con el nombre
 * del campo que hay que corregir.
 *
 * Se ata a `price` y no a los cinco a la vez porque si no se ejecutaría cinco
 * veces y duplicaría cada mensaje.
 *
 * ## Y `violationsIn()` es pública a propósito
 *
 * La previsualización del plan (RB-7) necesita saber si los términos son
 * coherentes **antes** de calcular, y tiene que preguntárselo a esta lista y
 * no a una copia suya: dos listas de las mismas seis reglas acaban
 * discrepando, y la que se quede corta pintará una cuota de un plan que el
 * formulario va a rechazar.
 */
final class CoherentFinancingTerms implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    /** Tope del precio, en céntimos de dólar: un millón. */
    public const MAX_PRICE_CENTS = 100_000_000;

    /**
     * Cuotas y días entre cuotas, mínimos y máximos (`REQ-LAY-004` RB-7).
     *
     * Son los de `ScheduleGenerator` y no una copia: si el backoffice dejara
     * guardar un plan que el cronograma no admite, el producto se vendería y
     * el apartado no se podría crear.
     */
    public const MIN_INSTALLMENTS = ScheduleGenerator::MIN_INSTALLMENTS;

    public const MAX_INSTALLMENTS = ScheduleGenerator::MAX_INSTALLMENTS;

    public const MIN_FREQUENCY_DAYS = ScheduleGenerator::MIN_FREQUENCY_DAYS;

    public const MAX_FREQUENCY_DAYS = ScheduleGenerator::MAX_FREQUENCY_DAYS;

    /**
     * Los datos completos de la petición, que es lo que hace posible cruzar
     * cinco campos desde una regla atada a uno.
     *
     * @var array<string, mixed>
     */
    private array $data = [];

    private Validator $validator;

    /**
     * Las seis reglas, en crudo y sin Laravel de por medio.
     *
     * @param  Money|null  $compareAtPrice  el precio tachado, si lo hay
     * @return array<string, string> campo => motivo; vacío si los términos son coherentes
     */
    public static function violationsIn(
        Money $price,
        ?Money $compareAtPrice,
        Money $downPayment,
        int $installmentsCount,
        int $frequencyDays,
        int $stock,
    ): array {
        $violations = [];

        if ($price->cents <= 0) {
            $violations['price'] = 'El precio tiene que ser mayor que cero.';
        } elseif ($price->cents > self::MAX_PRICE_CENTS) {
            $violations['price'] = 'El precio no puede pasar de $1.000.000.';
        }

        if ($downPayment->cents < 0) {
            $violations['down_payment_cents'] = 'La cuota inicial no puede ser negativa.';
        } elseif ($downPayment->cents >= $price->cents) {
            // SP-1 · **estrictamente** menor. Con la inicial igual al precio no
            // queda nada que financiar y el apartado sería una compra al
            // contado con otro nombre.
            $violations['down_payment_cents'] = 'La cuota inicial tiene que ser menor que el precio: '
                .'con la inicial igual al precio no queda nada que financiar.';
        }

        if ($compareAtPrice instanceof Money && $compareAtPrice->cents <= $price->cents) {
            // SP-2 · el precio tachado tiene que ser mayor, o la oferta que
            // ve el cliente es falsa.
            $violations['compare_at_price'] = 'El precio tachado tiene que ser mayor que el precio de venta.';
        }

        if ($installmentsCount < self::MIN_INSTALLMENTS || $installmentsCount > self::MAX_INSTALLMENTS) {
            $violations['installments_count'] = sprintf(
                'El número de cuotas tiene que estar entre %d y %d.',
                self::MIN_INSTALLMENTS,
                self::MAX_INSTALLMENTS,
            );
        }

        if ($frequencyDays < self::MIN_FREQUENCY_DAYS || $frequencyDays > self::MAX_FREQUENCY_DAYS) {
            $violations['frequency_days'] = sprintf(
                'Los días entre cuotas tienen que estar entre %d y %d.',
                self::MIN_FREQUENCY_DAYS,
                self::MAX_FREQUENCY_DAYS,
            );
        }

        if ($stock < 0) {
            $violations['stock'] = 'Las existencias no pueden ser negativas.';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $price = $this->amount('price');
        $downPayment = $this->amount('down_payment');
        $installmentsCount = $this->integer('installments_count');
        $frequencyDays = $this->integer('frequency_days');
        $stock = $this->integer('stock');

        if ($price === null || $downPayment === null
            || $installmentsCount === null || $frequencyDays === null || $stock === null) {
            // Falta algo, o un importe no es un decimal de dos cifras. De eso
            // ya hablan `required` y `decimal` con su mensaje junto al campo, y
            // añadir aquí un segundo mensaje haría que un solo error pareciera
            // dos problemas distintos. Con el campo corregido, las seis reglas
            // vuelven a correr enteras.
            return;
        }

        $violations = self::violationsIn(
            $price,
            $this->amount('compare_at_price'),
            $downPayment,
            $installmentsCount,
            $frequencyDays,
            $stock,
        );

        foreach ($violations as $field => $message) {
            // `down_payment_cents` es el nombre del campo en la base; el del
            // formulario es `down_payment`. La traducción vive aquí para que
            // `violationsIn()` pueda hablar en términos del modelo y la
            // pantalla reciba el nombre que de verdad tiene su `input`.
            $this->validator->errors()->add(self::formFieldFor($field), $message);
        }
    }

    /**
     * Cómo se llama en el formulario el campo del que habla cada regla.
     *
     * Pública porque `violationsIn()` lo es, y quien la llama de fuera del
     * formulario necesita la misma traducción: la completitud al publicar
     * (`REQ-BOF-020` RB-5) reparte los mismos mensajes por los mismos campos.
     * Una segunda tabla de equivalencias allí coincidiría hasta el día en que
     * un campo se renombrara.
     */
    public static function formFieldFor(string $field): string
    {
        return $field === 'down_payment_cents' ? 'down_payment' : $field;
    }

    /**
     * Un importe de la petición, o `null` si no es un decimal de dos cifras.
     *
     * SP-8 · «19.999» no se redondea: `Money::fromDecimalString()` lanza, y el
     * `422` lo pone la regla `decimal` del campo. Aquí se devuelve `null` para
     * no contar dos veces el mismo error.
     */
    private function amount(string $key): ?Money
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || $value === '' || (! is_string($value) && ! is_numeric($value))) {
            return null;
        }

        try {
            return Money::fromDecimalString((string) $value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Un entero de la petición, o `null` si no lo es.
     */
    private function integer(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
