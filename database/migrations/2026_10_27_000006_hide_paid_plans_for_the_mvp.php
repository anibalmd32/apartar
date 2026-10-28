<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->whereIn('code', ['pro', 'business'])->update(['is_public' => false]);
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('code', ['pro', 'business'])->update(['is_public' => true]);
    }
};
