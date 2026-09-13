<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

/**
 * El slug pedido ya lo tiene otra organización (`REQ-ORG-001` SP-1).
 *
 * Lleva **una variante libre** y no solo el hecho de estar ocupado, y esa es
 * toda la razón de que exista esta clase en vez de un error de validación
 * corriente. Es la primera pantalla que ve un cliente: la diferencia entre
 * ofrecerle `ferreteria-el-tornillo-2` y decirle «ese nombre no está
 * disponible» es la diferencia entre un registro que termina y uno que se
 * abandona.
 *
 * `organizations.slug` es único en toda la plataforma porque es una URL
 * pública compartible (`REQ-ORG-002` RB-3), así que el choque es entre
 * negocios que no se conocen: quien lo sufre no ha hecho nada mal y no tiene
 * forma de saber por qué.
 */
final class SlugTakenException extends RuntimeException
{
    /**
     * @param  string  $slug  el que se pidió
     * @param  string  $suggestion  una variante libre
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $suggestion,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function suggesting(string $slug, string $suggestion): self
    {
        return new self($slug, $suggestion, sprintf(
            'El enlace «%s» ya lo está usando otro negocio. «%s» está libre.',
            $slug,
            $suggestion,
        ));
    }

    /**
     * El slug no está ocupado por nadie: lo ocupa la propia plataforma.
     *
     * `/precios` o `/backoffice` son rutas literales que ganan al comodín de
     * la vitrina ([05 §7.2]), así que un negocio llamado así vería su web
     * mostrando otra cosa, sin ningún error en ningún sitio. El mensaje no
     * dice «ocupado por otro negocio» porque sería mentira y llevaría a
     * intentarlo con otro nombre parecido.
     */
    public static function reserved(string $slug, string $suggestion): self
    {
        return new self($slug, $suggestion, sprintf(
            'El enlace «%s» está reservado por la plataforma. «%s» está libre.',
            $slug,
            $suggestion,
        ));
    }
}
