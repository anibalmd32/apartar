<?php

declare(strict_types=1);

use App\Models\ExportJob;

it('fija el enlace en 24 horas y la retención en siete días', function (): void {
    expect(ExportJob::LINK_TTL_HOURS)->toBe(24)->and(ExportJob::RETENTION_DAYS)->toBe(7);
});
