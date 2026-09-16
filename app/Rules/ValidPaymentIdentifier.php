<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Payment\PaymentMethod;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Comprueba que el `identifier` de una cuenta de cobro tiene la forma que su
 * método exige (`REQ-ORG-007` RB-2, SP-5).
 *
 * **Por qué se valida el formato en vez de guardar lo que sea.** El
 * `identifier` no lo lee el sistema: lo lee **un cliente que va a mover dinero
 * con él**. Un teléfono mal escrito en una cuenta de pago móvil no da un error
 * en ninguna parte —da una transferencia a otra persona, o rechazada en el
 * banco a las nueve de la noche de un sábado, con un cliente que cree que ya
 * pagó su cuota—. El daño no aparece aquí; aparece tres pasos más allá y le
 * toca a quien menos culpa tiene.
 *
 * ## De dónde sale cada formato
 *
 * RB-2 dice **qué** va en cada método; la forma concreta es la del instrumento
 * real:
 *
 *  - **Pago móvil** · un móvil venezolano: prefijo de operadora (`0412`,
 *    `0414`, `0416`, `0424`, `0426`) y siete dígitos. Se aceptan las tres
 *    grafías con las que la gente lo escribe —`0414-1234567`, `04141234567` y
 *    `+58 414 1234567`—, porque rechazar un guion sería rechazar un dato
 *    correcto. Lo que sí rebota es un número que no es venezolano, que es
 *    literalmente el caso de SP-5.
 *  - **Transferencia** · los **20 dígitos** de una cuenta bancaria nacional.
 *    Es un número de longitud fija y un dígito de menos es otra cuenta.
 *  - **Zelle** · un correo, que es lo que RB-2 declara. Zelle también admite
 *    un teléfono de Estados Unidos, y aun así aquí no se acepta: la spec fija
 *    el correo y ampliarlo sería inventar contrato en una tarea que no es la
 *    suya.
 *  - **Binance Pay** · el Pay ID numérico (8 a 20 dígitos) o el correo de la
 *    cuenta, que son las dos cosas que Binance acepta como destino.
 *  - **Efectivo** · texto libre, con un mínimo. No hay número que comprobar,
 *    pero «aquí» tampoco es una dirección: lo único que se exige es que diga
 *    lo bastante como para que alguien pueda presentarse.
 *
 * El `match` es exhaustivo y sin `default`: un método nuevo sin formato
 * decidido lanza `UnhandledMatchError` en el primer test que lo toque, en vez
 * de caer del lado que lo acepta todo —que es el lado en que un dato de pago
 * inválido llega al catálogo—.
 */
final readonly class ValidPaymentIdentifier implements ValidationRule
{
    /** Prefijos de operadora móvil venezolana, sin el cero inicial. */
    private const MOBILE_PREFIXES = ['412', '414', '416', '424', '426'];

    /** Dígitos de una cuenta bancaria nacional. */
    private const ACCOUNT_DIGITS = 20;

    /** Mínimo de una dirección de entrega en efectivo. */
    private const ADDRESS_MINIMUM = 10;

    public function __construct(private PaymentMethod $method) {}

    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $identifier = is_string($value) ? trim($value) : '';

        if ($identifier === '') {
            $fail($this->expectation());

            return;
        }

        $valid = match ($this->method) {
            PaymentMethod::PagoMovil => $this->isVenezuelanMobile($identifier),
            PaymentMethod::Transferencia => $this->isNationalAccountNumber($identifier),
            PaymentMethod::Zelle => $this->isEmail($identifier),
            PaymentMethod::BinancePay => $this->isBinancePayId($identifier) || $this->isEmail($identifier),
            PaymentMethod::EfectivoUsd, PaymentMethod::EfectivoVes => $this->isDeliveryAddress($identifier),
        };

        if (! $valid) {
            $fail($this->expectation());
        }
    }

    /**
     * Lo que se le dice a quien escribió el dato.
     *
     * Dice **qué se esperaba**, no que lo suyo esté mal: quien rellena esto
     * está copiando de su libreta del banco y lo que necesita es saber en qué
     * formato. Un «el identificador no es válido» le deja probando variantes.
     */
    private function expectation(): string
    {
        return match ($this->method) {
            PaymentMethod::PagoMovil => 'Escribe un teléfono venezolano de pago móvil, como 0414-1234567.',
            PaymentMethod::Transferencia => 'El número de cuenta tiene 20 dígitos, los mismos que aparecen en tu libreta.',
            PaymentMethod::Zelle => 'Escribe el correo con el que recibes Zelle.',
            PaymentMethod::BinancePay => 'Escribe tu Pay ID de Binance (solo números) o el correo de tu cuenta.',
            PaymentMethod::EfectivoUsd, PaymentMethod::EfectivoVes => 'Indica dónde se entrega el efectivo, con dirección suficiente para llegar.',
        };
    }

    /**
     * Móvil venezolano en cualquiera de sus tres grafías habituales.
     *
     * Se normaliza a los diez dígitos nacionales antes de comparar, porque
     * `0414-1234567`, `04141234567` y `+58 414 1234567` son el mismo teléfono
     * y rechazar dos de las tres formas sería rechazar un dato correcto por
     * cómo se escribió.
     */
    private function isVenezuelanMobile(string $identifier): bool
    {
        $digits = $this->digitsOf($identifier);

        // `+58` delante: fuera el prefijo de país.
        if (str_starts_with($digits, '58') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        // Cero nacional delante: fuera también.
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return false;
        }

        return in_array(substr($digits, 0, 3), self::MOBILE_PREFIXES, true);
    }

    /**
     * Cuenta bancaria nacional: veinte dígitos exactos.
     *
     * Se admiten separadores porque la libreta los trae; lo que no se admite
     * es otra longitud, que es siempre otra cuenta o un número a medio copiar.
     */
    private function isNationalAccountNumber(string $identifier): bool
    {
        return strlen($this->digitsOf($identifier)) === self::ACCOUNT_DIGITS;
    }

    /**
     * Pay ID de Binance: solo dígitos, sin separadores.
     *
     * A diferencia del teléfono y de la cuenta, aquí **no** se limpia nada: el
     * Pay ID se copia y se pega del propio Binance, así que un espacio o un
     * guion son el síntoma de que lo pegado no es un Pay ID.
     */
    private function isBinancePayId(string $identifier): bool
    {
        return preg_match('/^\d{8,20}$/', $identifier) === 1;
    }

    private function isEmail(string $identifier): bool
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Dirección de entrega: lo bastante como para que alguien se presente.
     *
     * Es el único formato que no describe un instrumento, y por eso el único
     * que se comprueba por longitud. El mínimo no pretende validar una
     * dirección —eso no se puede—: pretende que «aquí» no pase por una.
     */
    private function isDeliveryAddress(string $identifier): bool
    {
        return mb_strlen($identifier) >= self::ADDRESS_MINIMUM;
    }

    private function digitsOf(string $identifier): string
    {
        return (string) preg_replace('/\D/', '', $identifier);
    }
}
