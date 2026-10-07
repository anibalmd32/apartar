<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dónde y cómo vive en disco el archivo de un comprobante (`REQ-PAY-002`).
 *
 * ## La ruta empieza por la organización, y la organización es la del apartado
 *
 * `{organization_id}/{uuid}.{ext}` dentro del disco `receipts` (RB-4): el
 * aislamiento es también físico, y se audita con un `ls`. La organización la
 * pasa quien llama **leída del apartado**, nunca del contexto de la petición
 * —la convención de «lo que decide dónde vive un dato viaja con el dato»—: si
 * se compusiera con el tenant fijado, un comprobante guardado con el contexto
 * equivocado acabaría en la carpeta de otro negocio sin ningún error.
 *
 * El nombre es un UUID (RB-7): el original del cliente no toca el sistema de
 * archivos, ni para bien ni para mal —acentos, rutas relativas, `foto.php`—.
 *
 * ## Los límites son constantes, y una de ellas no debería serlo
 *
 * `MAX_SIZE_MB` es `platform_settings.receipt_max_size_mb` (RB-5), un ajuste
 * que el administrador de la plataforma podrá cambiar. Hoy no hay lector de
 * `platform_settings` —llega con `T-086`—, así que vale su valor por defecto,
 * y `ReceiptFileTest` se pone rojo el día que exista el modelo para que nadie
 * se quede leyendo la constante.
 */
final class ReceiptFile
{
    /** El tope de `REQ-PAY-002` RB-5, en megabytes. Ver arriba. */
    public const MAX_SIZE_MB = 5;

    /**
     * Píxeles a partir de los cuales una imagen no se abre (SP-8).
     *
     * Cuarenta megapíxeles holgados: cualquier captura de móvil cabe —un
     * teléfono de gama alta da doce o cincuenta, y una captura de pantalla
     * menos de cuatro—, y una imagen de 50.000 × 50.000 de un color, que pesa
     * unos kilobytes comprimida, pediría casi diez gigas a GD al abrirla.
     */
    public const MAX_PIXELS = 40_000_000;

    /** El `accept` del `<input type="file">`: las cinco extensiones de RB-5. */
    public const ACCEPT = '.jpg,.jpeg,.png,.webp,.pdf';

    /**
     * El `sha256` del archivo **tal como se subió** (`REQ-PAY-003` RB-7).
     *
     * Antes de guardarlo y antes de recomprimirlo: la recompresión cambia los
     * bytes, y un hash calculado después no reconocería la misma captura
     * subida dos veces. Lo usan la regla del formulario y la acción, y por eso
     * vive aquí: si cada una lo calculara a su manera, la primera barrera y la
     * segunda dejarían de mirar lo mismo.
     *
     * @throws InvalidArgumentException si el archivo subido ya no se puede leer
     */
    public static function fingerprint(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $hash = $path === false ? false : hash_file('sha256', $path);

        if ($hash === false) {
            throw new InvalidArgumentException(sprintf('No se pudo leer el archivo subido «%s».', $file->getClientOriginalName()));
        }

        return $hash;
    }

    /**
     * Una ruta nueva para un archivo de esa organización, relativa al disco.
     *
     * @throws InvalidArgumentException si la organización no es un id o la extensión no es una extensión
     */
    public static function pathFor(int $organizationId, string $extension): string
    {
        // Un `organization_id` nulo pasado por `(int)` es un cero, y la
        // carpeta `0/` sería el cajón común de todos los comprobantes a los
        // que se les perdió el negocio. Mejor no guardar que guardar ahí.
        if ($organizationId < 1) {
            throw new InvalidArgumentException(sprintf('La organización %d no es un id: el comprobante no tiene dónde guardarse.', $organizationId));
        }

        $extension = mb_strtolower($extension);

        if (preg_match('/^[a-z0-9]{1,5}$/', $extension) !== 1) {
            throw new InvalidArgumentException(sprintf('«%s» no es una extensión de archivo.', $extension));
        }

        return sprintf('%d/%s.%s', $organizationId, Str::uuid()->toString(), $extension);
    }
}
