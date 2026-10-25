<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantSurface;

interface DeclaresTenantSurface
{
    public static function surface(): TenantSurface;
}
