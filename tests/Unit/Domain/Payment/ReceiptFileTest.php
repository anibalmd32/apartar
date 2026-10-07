<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptFile;
use App\Rules\RealMimeType;
use Illuminate\Http\UploadedFile;

/**
 * Dónde vive el archivo de un comprobante y con qué límites
 * (`REQ-PAY-002` RB-4, RB-5, RB-7, SP-8; `REQ-PAY-003` RB-7).
 */

/**
 * RB-4 y RB-7: la carpeta es la organización y el nombre un UUID con la
 * extensión, en minúsculas. Nada del nombre original.
 */
it('compone la ruta con la organización y un UUID', function (): void {
    expect(ReceiptFile::pathFor(42, 'JPG'))
        ->toMatch('#^42/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#');
});

it('da una ruta distinta cada vez', function (): void {
    expect(ReceiptFile::pathFor(7, 'pdf'))->not->toBe(ReceiptFile::pathFor(7, 'pdf'));
});

/**
 * Un `organization_id` nulo que llegue como cero no acaba en la carpeta `0/`,
 * el cajón común de los comprobantes que perdieron su negocio.
 */
it('no compone una ruta sin organización', function (int $organizationId): void {
    ReceiptFile::pathFor($organizationId, 'jpg');
})->with([0, -3])->throws(InvalidArgumentException::class);

it('no acepta como extensión lo que no lo es', function (string $extension): void {
    ReceiptFile::pathFor(1, $extension);
})->with(['', 'jpg/../../x', 'p.hp', 'demasiadolarga'])->throws(InvalidArgumentException::class);

/**
 * `REQ-PAY-003` RB-7: el hash es el del archivo tal como se subió, el mismo
 * que daría `sha256sum`.
 */
it('calcula el sha256 del archivo subido', function (): void {
    $path = sys_get_temp_dir().'/'.uniqid('apartar-receipt-', true);
    file_put_contents($path, 'la captura del pago móvil');

    expect(ReceiptFile::fingerprint(new UploadedFile($path, 'captura.jpg', 'image/jpeg', null, true)))
        ->toBe(hash('sha256', 'la captura del pago móvil'));
});

it('declara los límites de RB-5 y SP-8', function (): void {
    expect(ReceiptFile::MAX_SIZE_MB)->toBe(5)
        ->and(ReceiptFile::MAX_PIXELS)->toBe(40_000_000)
        ->and(ReceiptFile::ACCEPT)->toBe('.jpg,.jpeg,.png,.webp,.pdf');
});

/**
 * El `accept` del formulario y lo que la regla y la acción admiten son la
 * misma lista: si una cambia sin la otra, el selector ofrece lo que luego se
 * rechaza, o esconde lo que se habría aceptado.
 */
it('ofrece en el accept exactamente las extensiones que se admiten', function (): void {
    $admitted = array_merge(...array_values(RealMimeType::RECEIPT_TYPES));

    expect(explode(',', ReceiptFile::ACCEPT))->toEqualCanonicalizing(array_map(fn (string $extension): string => '.'.$extension, $admitted));
});

/**
 * **Aplazado a `T-086`.** El tope es `platform_settings.receipt_max_size_mb`
 * (RB-5) y hoy no hay quien lo lea, así que vale la constante. El día que
 * llegue el modelo, este caso se pone rojo para que `MAX_SIZE_MB` deje de ser
 * la fuente.
 */
it('sigue usando la constante porque no hay lector de platform_settings', function (): void {
    expect(file_exists(dirname(__DIR__, 4).'/app/Models/PlatformSetting.php'))->toBeFalse(
        'Llegó PlatformSetting (T-086): el tope del comprobante tiene que leer `receipt_max_size_mb` de ahí '
        .'(REQ-PAY-002 RB-5) en vez de ReceiptFile::MAX_SIZE_MB —en SubmitReceiptAction y en el formulario— y este caso se borra.',
    );
});
