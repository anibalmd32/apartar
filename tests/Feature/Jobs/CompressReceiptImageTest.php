<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Payment\ReceiptFile;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\CompressReceiptImage;
use App\Models\Organization;
use App\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

/**
 * `CompressReceiptImage` (`T-059`, `REQ-PAY-002` RB-6, SP-7, SP-8).
 *
 * Contra imágenes de verdad pintadas por GD y el disco `receipts` falseado,
 * que es un disco local: el job lee, recomprime con `spatie/image` y sustituye
 * igual que lo haría en producción. Cada caso corre el job **sin
 * organización fijada**, como lo encuentra un *worker*.
 */
final class CompressReceiptImageTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase, UploadsReceiptFiles;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);

        $this->organization = Organization::factory()->create();
    }

    /**
     * RB-6 · una captura apaisada de 3000 px queda en 2000 de ancho, con su
     * proporción; `file_size` dice lo que pesa ahora y `file_hash` sigue
     * siendo el del archivo subido (`REQ-PAY-003` RB-7).
     */
    public function test_a_large_jpeg_is_resized_to_2000_pixels(): void
    {
        $receipt = $this->storedReceipt($this->receiptImage(3000, 1500), 'image/jpeg');
        $hash = $receipt->file_hash;

        $this->compressNow($receipt);

        $stored = $this->fresh($receipt);
        $contents = (string) Storage::disk(Receipt::DISK)->get($stored->file_path);
        $this->assertSame([2000, 1000], $this->dimensionsOf($contents));
        $this->assertSame(strlen($contents), $stored->file_size);
        $this->assertSame($hash, $stored->file_hash, 'Recomprimir cambió el hash (REQ-PAY-003 RB-7).');
        $this->assertSame([$stored->file_path], Storage::disk(Receipt::DISK)->allFiles(), 'Quedó el temporal en el disco.');
    }

    /**
     * `REQ-SUB-003` SP-4 · recomprimir **baja** `storage_mb` en el acto. El
     * comprobante declara 3 MB —lo que pesaba la foto del móvil— y tras la
     * recompresión pesa unos KB: el contador pasa de 3 a 1 sin esperar a la
     * noche.
     */
    public function test_recompressing_lowers_the_storage_counter(): void
    {
        $receipt = $this->storedReceipt($this->receiptImage(3000, 1500), 'image/jpeg');
        DB::table('receipts')->where('id', $receipt->getKey())->update(['file_size' => 3 * 1024 * 1024]);
        $this->recalculateUsage($this->organization);
        $this->assertSame(3, $this->storageCounter());

        $this->compressNow($receipt);

        $this->assertLessThan(1024 * 1024, $this->fresh($receipt)->file_size);
        $this->assertSame(1, $this->storageCounter());
    }

    private function storageCounter(): int
    {
        return (int) DB::table('usage_counters')
            ->where('organization_id', $this->organization->getKey())
            ->where('metric', 'storage_mb')
            ->value('value');
    }

    /**
     * RB-6 · calidad 80, y no la que traiga la librería por defecto: el
     * resultado pesa lo mismo que la misma imagen recodificada a 80 —GD es
     * determinista— y distinto que a 100.
     */
    public function test_the_jpeg_is_encoded_at_quality_80(): void
    {
        $this->assertSame(80, CompressReceiptImage::QUALITY);

        $receipt = $this->storedReceipt($this->receiptImage(3000, 1500), 'image/jpeg');
        $original = (string) Storage::disk(Receipt::DISK)->get($receipt->file_path);

        $this->compressNow($receipt);

        $compressed = strlen((string) Storage::disk(Receipt::DISK)->get($this->fresh($receipt)->file_path));
        $this->assertSame($this->reencoded($original, 80), $compressed);
        $this->assertNotSame($this->reencoded($original, 100), $compressed);
    }

    /**
     * Los bytes de esa imagen llevada a 2000 px con esa calidad, con la misma
     * librería y el mismo driver que el job.
     */
    private function reencoded(string $contents, int $quality): int
    {
        $source = tempnam(sys_get_temp_dir(), 'ref').'.jpg';
        $target = tempnam(sys_get_temp_dir(), 'ref').'.jpg';
        file_put_contents($source, $contents);

        try {
            Image::useImageDriver((string) config('media-library.image_driver', 'gd'))->loadFile($source)
                ->fit(Fit::Max, CompressReceiptImage::MAX_SIDE, CompressReceiptImage::MAX_SIDE)
                ->quality($quality)
                ->save($target);

            return (int) filesize($target);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    /**
     * El lado mayor, sea cual sea: una captura vertical de móvil.
     */
    public function test_a_tall_png_is_resized_by_its_longer_side(): void
    {
        $receipt = $this->storedReceipt($this->receiptImage(1000, 2500, 'png'), 'image/png');

        $this->compressNow($receipt);

        $this->assertSame([800, 2000], $this->dimensionsOf((string) Storage::disk(Receipt::DISK)->get($receipt->file_path)));
    }

    /**
     * Una imagen que ya cabe se deja como llegó: volver a codificarla solo
     * pierde calidad.
     */
    public function test_a_small_image_is_left_intact(): void
    {
        $receipt = $this->storedReceipt($this->receiptImage(1200, 800), 'image/jpeg');
        $before = Storage::disk(Receipt::DISK)->get($receipt->file_path);

        $this->compressNow($receipt);

        $this->assertSame($before, Storage::disk(Receipt::DISK)->get($receipt->file_path));
        $this->assertSame($receipt->file_size, $this->fresh($receipt)->file_size);
    }

    /**
     * Ni el PDF —no es una imagen— ni el WebP —el GD del contenedor no lo
     * codifica— se tocan.
     */
    public function test_pdf_and_webp_are_not_touched(): void
    {
        $pdf = $this->storedReceipt($this->receiptPdf(), 'application/pdf', 'pdf');
        $webp = $this->storedReceipt($this->receiptUpload('captura.webp', 'RIFF'.pack('V', 18).'WEBPVP8L'.pack('V', 10).str_repeat("\x2f", 10)), 'image/webp', 'webp');
        $before = [Storage::disk(Receipt::DISK)->get($pdf->file_path), Storage::disk(Receipt::DISK)->get($webp->file_path)];

        $this->compressNow($pdf);
        $this->compressNow($webp);

        $this->assertSame($before, [Storage::disk(Receipt::DISK)->get($pdf->file_path), Storage::disk(Receipt::DISK)->get($webp->file_path)]);
        $this->assertFalse(CompressReceiptImage::compresses('image/webp'));
        $this->assertFalse(CompressReceiptImage::compresses('application/pdf'));
        $this->assertTrue(CompressReceiptImage::compresses('image/jpeg'));
        $this->assertTrue(CompressReceiptImage::compresses('image/png'));
    }

    /**
     * **SP-8 · el límite de píxeles se mira antes de abrir.** Un PNG que dice
     * medir 8000 × 6000 —48 megapíxeles, más que `MAX_PIXELS`— no se abre:
     * se registra por qué y se deja como está. La cabecera es de verdad y el
     * resto no existe, así que si el job intentara abrirlo fallaría por otro
     * motivo, con otro aviso: el que se afirma es el de los píxeles.
     */
    public function test_an_image_over_the_pixel_limit_is_never_opened(): void
    {
        $log = Log::spy();
        $receipt = $this->storedReceipt($this->receiptUpload('enorme.png', $this->pngHeader(8000, 6000)), 'image/png', 'png');
        $before = Storage::disk(Receipt::DISK)->get($receipt->file_path);

        $this->compressNow($receipt);

        $this->assertSame($before, Storage::disk(Receipt::DISK)->get($receipt->file_path));
        $log->shouldHaveReceived('warning')->once()->with('receipt.compression.too_many_pixels', Mockery::on(
            fn (array $context): bool => $context['width'] === 8000 && $context['height'] === 6000 && $context['max_pixels'] === ReceiptFile::MAX_PIXELS,
        ));
        $log->shouldNotHaveReceived('warning', ['receipt.compression.failed', Mockery::any()]);
    }

    /**
     * **SP-7 · si la recompresión falla, el original se queda.** Un PNG de
     * 3000 × 3000 cortado a la mitad: la cabecera dice que hay que reducirlo
     * y GD no puede leerlo. El archivo y `file_size` siguen como estaban, el
     * fallo se registra, y el job termina sin relanzar.
     */
    public function test_a_failed_compression_keeps_the_original(): void
    {
        $log = Log::spy();
        $complete = (string) file_get_contents((string) $this->receiptImage(3000, 3000, 'png')->getRealPath());
        $receipt = $this->storedReceipt($this->receiptUpload('rota.png', substr($complete, 0, intdiv(strlen($complete), 2))), 'image/png', 'png');
        $before = Storage::disk(Receipt::DISK)->get($receipt->file_path);

        $this->compressNow($receipt);

        $this->assertSame($before, Storage::disk(Receipt::DISK)->get($receipt->file_path));
        $this->assertSame($receipt->file_size, $this->fresh($receipt)->file_size);
        $this->assertSame([$receipt->file_path], Storage::disk(Receipt::DISK)->allFiles());
        $log->shouldHaveReceived('warning')->once()->with('receipt.compression.failed', Mockery::on(
            fn (array $context): bool => $context['receipt_id'] === (int) $receipt->getKey(),
        ));
    }

    /**
     * El job trabaja en la organización de su payload: con la de otro
     * negocio, el comprobante no existe para él y no toca nada. Y al
     * terminar deja el contexto limpio (`RunsInTenantContext`).
     */
    public function test_it_only_sees_the_receipt_in_the_organization_of_its_payload(): void
    {
        $log = Log::spy();
        $receipt = $this->storedReceipt($this->receiptImage(3000, 1500), 'image/jpeg');
        $before = Storage::disk(Receipt::DISK)->get($receipt->file_path);

        (new CompressReceiptImage((int) $receipt->getKey(), (int) $this->neighbourOrganization()->getKey()))->handle();

        $this->assertSame($before, Storage::disk(Receipt::DISK)->get($receipt->file_path));
        $this->assertFalse(app(TenantContext::class)->hasOrganization());
        $log->shouldHaveReceived('warning')->once()->with('receipt.compression.missing', Mockery::any());
    }

    /**
     * `REQ-INF-003`: las conversiones de imagen van a la cola `default`.
     */
    public function test_it_runs_on_the_default_queue(): void
    {
        $this->assertSame('default', (new CompressReceiptImage(1, 1))->queue);
    }

    // -------------------------------------------------------------------

    /**
     * Un comprobante cuyo archivo está en el disco, en la ruta de su
     * organización, con el hash, el tipo y el peso de lo subido.
     */
    private function storedReceipt(UploadedFile $file, string $mime, string $extension = 'jpg'): Receipt
    {
        $contents = (string) file_get_contents((string) $file->getRealPath());
        $path = ReceiptFile::pathFor((int) $this->organization->getKey(), $extension);
        Storage::disk(Receipt::DISK)->put($path, $contents);

        return $this->receiptFor($this->layawayIn($this->organization), attributes: [
            'file_path' => $path,
            'file_hash' => hash('sha256', $contents),
            'file_mime' => $mime,
            'file_size' => strlen($contents),
        ]);
    }

    /**
     * Corre el job como lo haría un *worker*: sin organización fijada.
     */
    private function compressNow(Receipt $receipt): void
    {
        $this->withoutOrganization();

        (new CompressReceiptImage((int) $receipt->getKey(), (int) $receipt->organization_id))->handle();

        $this->assertFalse(app(TenantContext::class)->hasOrganization(), 'El job dejó su organización fijada.');
    }

    private function fresh(Receipt $receipt): Receipt
    {
        return $this->withinOrganization($this->organization, fn (): Receipt => Receipt::query()->findOrFail($receipt->getKey()));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensionsOf(string $contents): array
    {
        $size = getimagesizefromstring($contents);
        $this->assertNotFalse($size, 'Lo guardado no es una imagen.');

        return [$size[0], $size[1]];
    }

    /**
     * La firma y el `IHDR` de un PNG de esas dimensiones, sin nada detrás:
     * lo que lee `getimagesize()` y nada que se pueda abrir.
     */
    private function pngHeader(int $width, int $height): string
    {
        $data = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).$data.pack('N', crc32($data));
    }
}
