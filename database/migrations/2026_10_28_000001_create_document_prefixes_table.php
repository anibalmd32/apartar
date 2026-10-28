<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREFIXES = [
        ['code' => 'V', 'name' => 'Venezolano', 'kind' => 'cedula'],
        ['code' => 'E', 'name' => 'Extranjero', 'kind' => 'cedula'],
        ['code' => 'J', 'name' => 'Jurídico', 'kind' => 'rif'],
    ];

    public function up(): void
    {
        Schema::create('document_prefixes', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 1)->unique();
            $table->string('name', 40);
            $table->string('kind', 10);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        $now = now();

        foreach (self::PREFIXES as $position => $prefix) {
            DB::table('document_prefixes')->insert($prefix + ['position' => $position + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_prefixes');
    }
};
