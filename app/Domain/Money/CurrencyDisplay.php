<?php

declare(strict_types=1);

namespace App\Domain\Money;

/**
 * Cuál de las dos monedas se muestra primero (`REQ-FX-004`).
 *
 * **No cambia el importe, cambia el orden en que se lee.** El mismo precio es
 * `$20.00 (Bs. 850,00)` o `Bs. 850,00 ($20.00)` según lo que le convenga a
 * quien mira: el cliente que va a pagar en bolívares quiere la cifra en
 * bolívares delante, y el negocio que lleva su contabilidad en dólares la
 * quiere al revés. Las dos representaciones viajan siempre (`MoneyView`), así
 * que elegir es puramente de presentación y no genera ninguna petición (RB-6).
 *
 * ## Es de la persona, no del negocio
 *
 * RB-3: la preferencia se conserva al pasar del catálogo de un negocio al de
 * otro y en el feed multi-negocio. `organization_settings.currency_display_default`
 * solo decide **el arranque** de quien todavía no ha elegido nada (RB-5); a
 * partir de ahí manda la persona.
 *
 * ## Este enum es el censo, y el único
 *
 * `SettingKey::CurrencyDisplayDefault` valida contra él en lugar de repetir
 * las dos cadenas, por lo mismo que `RateSource` desde `T-019`: con dos
 * listas, el día que aparezca un tercer modo una de ellas se queda corta y el
 * síntoma no es un test en rojo sino un `CurrencyDisplay::from()` que lanza
 * sobre un ajuste que la validación dio por bueno.
 *
 * Espejo de `CurrencyDisplay` en `resources/js/types/money.ts`.
 */
enum CurrencyDisplay: string
{
    /**
     * Nombre de la cookie que lleva la preferencia (RB-4).
     *
     * **Está excluida del cifrado de cookies de Laravel**, y no es un descanso
     * de seguridad: la escribe el propio navegador cuando se pulsa el toggle
     * —el cambio es puramente de cliente (RB-6)—, así que llega sin cifrar y
     * el servidor no podría descifrarla. Sin la exclusión, el valor se
     * descartaría en cada petición y el primer render volvería siempre al
     * default: el parpadeo que RB-4 existe para evitar, pero permanente.
     *
     * Lo que guarda es cuál de dos monedas va primero. No identifica a nadie
     * ni autoriza nada.
     */
    public const COOKIE = 'currency_display';

    /** Un año, como fija RB-4. */
    public const COOKIE_DAYS = 365;

    /** Dólares delante, bolívares entre paréntesis (RB-1). */
    case UsdPrimary = 'usd_primary';

    /** Bolívares delante, dólares entre paréntesis (RB-2). */
    case VesPrimary = 'ves_primary';

    /**
     * El modo con el que arranca quien no ha elegido nada y no tiene negocio
     * detrás (RB-5).
     *
     * Es el del feed, donde conviven varias organizaciones y ninguna manda, y
     * el de cualquier superficie sin tenant.
     */
    public static function platformDefault(): self
    {
        return self::UsdPrimary;
    }

    /**
     * El valor que llega de una cookie o de un ajuste, o `null` si no lo
     * reconocemos.
     *
     * SP-3: una cookie con basura **se descarta sin error**. No es un `422` ni
     * un log —nadie la escribió con mala intención y la persona no puede
     * hacer nada al respecto—: es un valor que no conocemos, y lo que toca es
     * caer al default y seguir.
     */
    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Las dos formas, para validar contra ellas.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $display): string => $display->value, self::cases());
    }
}
