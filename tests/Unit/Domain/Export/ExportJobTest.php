<?php

declare(strict_types=1);

use App\Models\ExportJob;

/**
 * `REQ-BOF-043` RB-5, RB-7 · el enlace vale 24 horas y el archivo, siete
 * días.
 */
it('fija el enlace en 24 horas y la retención en siete días', function (): void {
    expect(ExportJob::LINK_TTL_HOURS)->toBe(24)->and(ExportJob::RETENTION_DAYS)->toBe(7);
});

/**
 * **Aplazado a `T-086`.** El TTL es `platform_settings.export_link_ttl_hours`
 * y hoy no hay quien lo lea, así que vale la constante. El día que llegue el
 * modelo, este caso se pone rojo para que `LINK_TTL_HOURS` deje de ser la
 * fuente.
 */
it('sigue usando la constante porque no hay lector de platform_settings', function (): void {
    expect(file_exists(dirname(__DIR__, 4).'/app/Models/PlatformSetting.php'))->toBeFalse(
        'Llegó PlatformSetting (T-086): el enlace de descarga tiene que leer `export_link_ttl_hours` de ahí '
        .'(REQ-BOF-043 RB-5) en vez de ExportJob::LINK_TTL_HOURS, y este caso se borra.',
    );
});
