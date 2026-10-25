<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RETIRED_KEY = 'storefront_enabled';

    public function up(): void
    {
        DB::table('organization_settings')->where('key', self::RETIRED_KEY)->delete();
    }

    public function down(): void {}
};
