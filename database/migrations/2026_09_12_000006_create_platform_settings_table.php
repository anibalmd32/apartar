<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes globales de la plataforma (`REQ-PLT-006`, `D-9`).
 *
 * La mitad de plataforma del par que `D-9` separó: aquí viven los valores que
 * decide el operador (`receipt_max_size_mb`, `subscription_grace_days`,
 * `support_access_ttl_hours`…), y en `organization_settings` los que decide
 * cada negocio.
 *
 * La clave primaria es la **cadena** `key`, no un `id` autoincremental: el
 * ajuste se busca siempre por su nombre y una columna de identidad artificial
 * solo añadiría una segunda forma de referirse a la misma fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 64)->primary();

            // JSON y no una columna por tipo: los ajustes son enteros, listas
            // y booleanos, y añadir uno nuevo no debe exigir una migración.
            $table->json('value');

            // Quién lo cambió la última vez. Nullable porque un valor puede
            // venir del despliegue inicial y no de una persona; `nullOnDelete`
            // conserva el ajuste si el operador desaparece.
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('platform_users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
