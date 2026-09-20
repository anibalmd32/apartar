<?php

declare(strict_types=1);

namespace App\Support\Media;

use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Dónde vive en disco el archivo de un negocio
 * (`REQ-BOF-022` RB-9, `REQ-CAT-005` RB-7).
 *
 * `organizations/{organization_id}/media/{media_id}/`, y el primer segmento es
 * todo el punto.
 *
 * ## El aislamiento de este proyecto vive en el *global scope*; un archivo no
 * tiene *global scope*
 *
 * Hasta aquí, que una fila fuera de un negocio y no de otro lo garantizaba una
 * consulta filtrada. Un fichero en disco no se consulta: se sirve por su ruta.
 * Si las imágenes de todos los negocios cayeran en el mismo directorio bajo un
 * identificador correlativo, el aislamiento pasaría a depender **solo** de que
 * nadie adivine una URL — y el día que haya que responder «qué archivos son de
 * quién» para un borrado, una auditoría o una restauración, la respuesta
 * estaría en una tabla y no en el disco, que es donde hace falta.
 *
 * ## La organización sale del propio `media`, no del contexto
 *
 * Se lee de `custom_properties`, que viaja en la fila. La alternativa evidente
 * —preguntarle a `TenantContext`— es peor de una forma que no se ve: la ruta
 * dejaría de ser una función del archivo y pasaría a depender del estado
 * ambiental de quien la pide. Una lectura hecha bajo otra organización
 * compondría una ruta distinta y el archivo «no existiría», sin ningún error
 * que lo explicara. Y `$media->model->organization_id` tampoco vale: es una
 * consulta por cada resolución de ruta, y una que lanza cuando no hay tenant
 * fijado —justo en un *worker* o en un comando—.
 *
 * ## Y falla cerrado
 *
 * Un `media` sin esa propiedad **lanza** en vez de caer a un directorio
 * compartido. Es la misma convención que el resto del proyecto: la próxima
 * colección de medios que alguien añada sin declarar de quién es no se cuela
 * en silencio, se rompe en el primer test que la toque.
 */
final class TenantPathGenerator implements PathGenerator
{
    /**
     * Propiedad del `media` que dice de qué organización es el archivo.
     *
     * La escribe quien sube —es el único que lo sabe, desde el contexto de la
     * petición— y la lee esto. El reparto es deliberado: **el llamante declara
     * de quién es el archivo y el generador decide dónde va**, de modo que una
     * colección nueva no puede inventarse su propia estructura de carpetas.
     */
    public const ORGANIZATION_PROPERTY = 'organization_id';

    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    /**
     * La carpeta del archivo, con su organización delante.
     *
     * @throws RuntimeException si el `media` no declara a qué organización pertenece
     */
    private function basePath(Media $media): string
    {
        $organizationId = $media->getCustomProperty(self::ORGANIZATION_PROPERTY);

        if (! is_numeric($organizationId)) {
            throw new RuntimeException(sprintf(
                'El media %s no declara su organización en custom_properties["%s"], así que no hay ruta que '
                .'componer. Quien lo añade tiene que declararla: sin ella el archivo caería en un directorio '
                .'compartido y el aislamiento físico de `REQ-BOF-022` RB-9 dejaría de existir.',
                (string) $media->getKey(),
                self::ORGANIZATION_PROPERTY,
            ));
        }

        return sprintf('organizations/%d/media/%s', (int) $organizationId, (string) $media->getKey());
    }
}
