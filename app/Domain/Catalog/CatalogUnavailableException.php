<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;
use RuntimeException;

/**
 * Se envió un apartado desde un catálogo que ya no lo admite
 * (`REQ-LAY-001` SP-3, SP-5).
 *
 * Es el formulario que ya estaba abierto: el negocio despublicó el catálogo,
 * caducó su fecha o se suspendió la organización mientras el cliente escribía.
 * La ficha pública responde `404` o `410` a quien llega por la URL
 * (`PublicCatalogAccess`), pero aquí el cliente **lo estaba viendo hace un
 * minuto**, así que es un `422` con una frase y no una página de error.
 *
 * Una sola frase para todos los motivos. Distinguir «despublicado» de
 * «suspendido» le contaría al cliente la situación del negocio con la
 * plataforma, que no es asunto suyo (SP-5 la resuelve como SP-3 a propósito).
 */
final class CatalogUnavailableException extends RuntimeException
{
    /** Código HTTP con el que se responde (SP-3). */
    public const STATUS = 422;

    private function __construct(public readonly Catalog $catalog)
    {
        parent::__construct('Este catálogo ya no está disponible.');
    }

    public static function for(Catalog $catalog): self
    {
        return new self($catalog);
    }
}
