<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * Un enlace público que ya no se puede cambiar (`REQ-ORG-006` SP-5,
 * `REQ-ORG-001` RB-2, `REQ-CAT-022` RB-2 y SP-6).
 *
 * No es una restricción técnica: es que esas URLs se comparten por WhatsApp y
 * se pegan en una bio de Instagram, y cambiarlas rompe todos los enlaces que
 * ya están circulando. Quien lo hiciera no vería un error; vería que dejan de
 * llegarle clientes, sin nada que lo explique.
 *
 * Es distinto de `SlugTakenException`, y por eso no se reutiliza: aquel dice
 * «ese nombre es de otro» y tiene salida —una variante libre—; este dice «el
 * tuyo ya está en la calle» y no la tiene.
 *
 * ## Dos enlaces, dos cerrojos, un solo tipo
 *
 * La clase se generaliza en `T-032` en vez de nacer una segunda: la **forma**
 * es idéntica —`422`, sin vuelta atrás, nada que reescribir— y lo único que
 * cambia es **qué lo cerró**. Por eso hay un constructor con nombre por
 * cerrojo y ninguno genérico:
 *
 *  - el del negocio se cierra cuando **existe algún catálogo publicado**
 *    (`REQ-ORG-006` SP-5): mientras no haya ninguno, la vitrina no ha
 *    circulado;
 *  - el del catálogo se cierra con **su primera publicación** (`REQ-CAT-022`
 *    RB-2), y no se reabre al despublicarlo: el slug no se recicla (SP-7).
 *
 * Son condiciones distintas sobre cosas distintas, así que un mensaje único
 * —«tienes catálogos publicados»— sería falso justo en el caso del catálogo
 * que es el único publicado de un negocio que acaba de empezar. Lo que se
 * comparte es el tipo; lo que no, la frase.
 *
 * Es el tercer enlace del proyecto con esta regla. El del **producto**
 * (`REQ-BOF-020` SP-5) siguió otro camino en `T-027` —un mensaje de
 * validación junto al campo— y se queda ahí: su cerrojo se **abre** al
 * despublicar, así que lo que hay que decir es «despublícalo y podrás
 * cambiarlo», que es una corrección y no un final.
 *
 * `422`: la petición está bien formada y quien la hace tiene permiso de sobra.
 * Lo que no se puede es aceptarla.
 */
final class SlugLockedException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-5).
     */
    public const STATUS = 422;

    public static function hasPublishedCatalogs(string $slug): self
    {
        return new self(sprintf(
            'El enlace de tu negocio (%s) ya no se puede cambiar: tienes catálogos publicados y '
            .'cambiarlo rompería los enlaces que ya compartiste con tus clientes.',
            $slug,
        ));
    }

    /**
     * El enlace de un catálogo que ya se publicó alguna vez (`REQ-CAT-022`
     * RB-2, SP-6).
     *
     * El mensaje no dice «despublícalo primero» **a propósito**: despublicar
     * no lo reabre. El slug no se recicla ni se libera (SP-7), porque su URL
     * ya circuló y dársela a otro catálogo mandaría a esa gente al sitio
     * equivocado. Ofrecer una salida que no existe sería peor que no ofrecer
     * ninguna.
     */
    public static function catalogIsPublished(string $slug): self
    {
        return new self(sprintf(
            'El enlace de este catálogo (/c/%s) ya no se puede cambiar: se publicó y esa URL ya circula. '
            .'Despublicarlo tampoco lo libera, porque quien la tenga guardada seguiría llegando aquí. '
            .'Si necesitas otro enlace, crea un catálogo nuevo.',
            $slug,
        ));
    }
}
