<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Illuminate\Support\Str;

/**
 * Qué es un nombre «radicalmente distinto» (`REQ-IDN-001` SP-3).
 *
 * Es la condición de `customer.identity_conflict`, el evento que pide revisión
 * humana, y **tiene que ser rara** para que signifique algo: si saltara con
 * cada tilde o cada segundo apellido, el dueño aprendería a no mirarlo y
 * dejaría de avisar justo cuando alguien aparta con la cédula de otro.
 *
 * ## La regla
 *
 * Dos nombres divergen radicalmente cuando **no comparten ni una palabra**.
 * Antes de comparar se quita lo que no identifica a nadie: mayúsculas, tildes
 * y signos (`María` es `maria`), las iniciales sueltas (`J.`) y las
 * partículas (`de`, `del`, `la`…). Y dos palabras largas que difieren en una
 * sola letra cuentan como la misma: `Gonzales` y `González` son una errata,
 * no otra persona.
 *
 * Así, `María González` → `Maria Gonzales Pérez` es un cambio ordinario, y
 * `María González` → `Pedro Ramírez` es un conflicto.
 *
 * ## Se equivoca hacia el silencio, a propósito
 *
 * Con una palabra en común no salta, y eso deja pasar casos reales —dos
 * razones sociales que empiecen por `Inversiones`, dos hermanos que se llamen
 * `José`—. Es el lado correcto: el conflicto es la alarma, no el registro. El
 * registro de **toda** sobrescritura ya lo lleva `customer.contact_updated`,
 * con el nombre de antes y el de después, así que lo que esta regla deja pasar
 * no desaparece: queda escrito, sin alarma.
 *
 * Si una de las dos no deja ninguna palabra que comparar —solo iniciales—,
 * tampoco salta: no hay con qué afirmar que son personas distintas.
 */
final class NameDivergence
{
    /**
     * Partículas de nombres y apellidos, que dos personas distintas comparten
     * sin que eso diga nada.
     */
    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'da', 'do', 'dos'];

    /** Longitud a partir de la cual una letra de diferencia es una errata. */
    private const TYPO_TOLERANT_LENGTH = 5;

    public static function isRadical(string $before, string $after): bool
    {
        $was = self::words($before);
        $is = self::words($after);

        if ($was === [] || $is === []) {
            return false;
        }

        foreach ($was as $word) {
            foreach ($is as $candidate) {
                if (self::sameWord($word, $candidate)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Las palabras que identifican, sin tildes, sin mayúsculas y sin
     * partículas ni iniciales.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $ascii = mb_strtolower(Str::ascii($name));

        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $words === false ? [] : $words,
            fn (string $word): bool => strlen($word) > 1 && ! in_array($word, self::PARTICLES, true),
        ));
    }

    private static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return strlen($a) >= self::TYPO_TOLERANT_LENGTH
            && strlen($b) >= self::TYPO_TOLERANT_LENGTH
            && levenshtein($a, $b) <= 1;
    }
}
