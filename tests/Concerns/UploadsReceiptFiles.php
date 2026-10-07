<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Archivos de comprobante **de verdad** para los tests de `T-059`.
 *
 * No `UploadedFile::fake()`: su `getMimeType()` deduce el tipo del nombre,
 * que es justo lo que la subida existe para no hacer (`RealMimeTypeTest`
 * explica el resto). Cada archivo es un fichero temporal con contenido real:
 * una imagen pintada por GD —el mismo `image_driver` del proyecto— o un PDF
 * mínimo. Y **cada imagen es distinta** de la anterior, con rectángulos de
 * colores al azar, para que dos llamadas no compartan hash por casualidad: un
 * duplicado en estos tests es siempre uno que el test buscó.
 */
trait UploadsReceiptFiles
{
    /**
     * Una imagen JPEG o PNG de ese tamaño, con contenido único.
     */
    protected function receiptImage(int $width = 64, int $height = 48, string $type = 'jpeg', ?string $name = null): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD no pudo crear la imagen del test.');
        }

        for ($i = 0; $i < 24; $i++) {
            $color = (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            $x = random_int(0, $width - 1);
            $y = random_int(0, $height - 1);
            imagefilledrectangle($image, $x, $y, min($width - 1, $x + random_int(1, max(1, intdiv($width, 3)))), min($height - 1, $y + random_int(1, max(1, intdiv($height, 3)))), $color);
        }

        $path = $this->temporaryReceiptPath();

        match ($type) {
            'png' => imagepng($image, $path),
            default => imagejpeg($image, $path, 90),
        };

        $name ??= 'captura-del-pago.'.($type === 'png' ? 'png' : 'jpg');

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    /**
     * Un PDF mínimo pero real, con contenido único.
     */
    protected function receiptPdf(string $name = 'justificante.pdf'): UploadedFile
    {
        $contents = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
            .'% '.bin2hex(random_bytes(8))."\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        return $this->receiptUpload($name, $contents);
    }

    /**
     * Un archivo con ese nombre y ese contenido, sea lo que sea.
     */
    protected function receiptUpload(string $name, string $contents): UploadedFile
    {
        $path = $this->temporaryReceiptPath();
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    /**
     * Otro envío del **mismo** archivo, con el nombre que se quiera: mismo
     * contenido, mismo hash.
     */
    protected function sameFileAs(UploadedFile $file, string $name = 'otra-vez.jpg'): UploadedFile
    {
        return $this->receiptUpload($name, (string) file_get_contents((string) $file->getRealPath()));
    }

    private function temporaryReceiptPath(): string
    {
        return sys_get_temp_dir().'/'.uniqid('apartar-receipt-', true);
    }
}
