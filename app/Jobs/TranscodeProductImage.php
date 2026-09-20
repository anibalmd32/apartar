<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsInTenantContext;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Throwable;

/**
 * Genera las tres conversiones de una imagen de producto, en cola
 * (`REQ-CAT-005` RB-1, SP-1, `REQ-BOF-022` RB-3).
 *
 * ## Por qué extiende el job del paquete en vez de despacharse a mano
 *
 * Medialibrary ya encola las conversiones al añadir un medio, y el sitio que
 * su propia configuración deja para cambiarlo es `media-library.jobs`: «make
 * sure your custom jobs extend the ones provided by the package». Despachar
 * uno propio **además** del suyo haría el trabajo dos veces; desactivar el
 * suyo y despacharlo desde el controlador pondría en la subida una decisión
 * que es del medio, no de quien lo sube — y la próxima colección volvería a
 * tomarla por su cuenta.
 *
 * Así que este es el job que medialibrary despacha. Lo único que añade es lo
 * que el paquete no puede saber: **en qué organización trabaja**.
 *
 * ## Y eso no pasa solo
 *
 * `RunsInTenantContext`: un job de este proyecto no hereda el tenant de quien
 * lo encoló, porque entre encolar y ejecutar pasan minutos y el *worker* es
 * otro proceso. Sin el contexto fijado, todo lo que consulte o lanza —si toca
 * una tabla del tenant— o trabaja sobre la fila equivocada. La organización
 * sale de `custom_properties`, la misma fuente que usa `TenantPathGenerator`
 * para componer la ruta: una sola declaración de a quién pertenece el archivo.
 *
 * ## Si falla, la imagen sigue sirviendo
 *
 * SP-6 de `REQ-BOF-022` y SP-1 de `REQ-CAT-005`: un job de conversión que
 * falla **no deja el producto inservible**. La imagen queda utilizable con su
 * original —`ImageSet` cae al original mientras la conversión no exista
 * (RB-3)— y se marca para revisión, que es lo que el backoffice enseña. Un
 * producto publicable cuyas fotos dependan de que la cola vaya bien es un
 * catálogo que se apaga cuando cae un *worker*.
 */
final class TranscodeProductImage extends PerformConversionsJob
{
    use RunsInTenantContext;

    /**
     * Propiedad con la que el medio queda marcado para revisión (SP-6).
     *
     * Vive en el propio `media` y no en una tabla aparte porque es un estado
     * del archivo, no del producto: dos imágenes del mismo producto pueden
     * estar una bien y otra pendiente de revisar.
     */
    public const REVIEW_PROPERTY = 'conversion_failed';

    /**
     * La organización del archivo, tal como la declaró quien lo subió.
     *
     * @throws RuntimeException si el medio no la declara
     */
    public function tenantOrganizationId(): int
    {
        $organizationId = $this->media->getCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY);

        if (! is_numeric($organizationId)) {
            throw new RuntimeException(sprintf(
                'El media %s no declara su organización, así que este job no sabe en qué tenant trabajar. '
                .'Es el mismo dato que `TenantPathGenerator` necesita para componer su ruta.',
                (string) $this->media->getKey(),
            ));
        }

        return (int) $organizationId;
    }

    /**
     * Convierte, con la organización del archivo fijada.
     *
     * @throws ModelNotFoundException si el payload trae una organización que ya no existe
     */
    public function handle(FileManipulator $fileManipulator): bool
    {
        return (bool) $this->runInTenantContext(fn (): bool => parent::handle($fileManipulator));
    }

    /**
     * SP-6 · la conversión falló y la imagen **sigue siendo usable**.
     *
     * Se marca para revisión y nada más: no se borra el medio, no se despublica
     * el producto y no se reintenta en bucle. Lo que el negocio ve en su
     * galería es «pendiente de revisar» sobre una imagen que su catálogo está
     * sirviendo igual, con el original.
     *
     * El marcado va **fuera** del contexto del tenant a propósito: `media` no
     * es una tabla del tenant —se ata a su modelo por relación polimórfica— y
     * pedir el contexto aquí convertiría un fallo de conversión en un segundo
     * fallo al intentar anotarlo.
     */
    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('La conversión de una imagen falló sin excepción.'));

        $this->media->setCustomProperty(self::REVIEW_PROPERTY, true)->save();
    }
}
