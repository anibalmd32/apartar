<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAMES = ['free' => ['Free', 'Gratis'], 'business' => ['Business', 'Negocio']];

    public function up(): void
    {
        foreach (self::NAMES as $code => [$old, $new]) {
            DB::table('plans')->where('code', $code)->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $code => [$old, $new]) {
            DB::table('plans')->where('code', $code)->where('name', $new)->update(['name' => $old]);
        }
    }
};
