<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes por organización (`REQ-ORG-006`, `D-9`).
 *
 * La mitad de tenant del par que `D-9` separó: aquí viven las once claves de
 * [02 §9.1] —origen de la tasa, período de gracia, tolerancia de liquidación,
 * cuotas por defecto…— y en `platform_settings` las que decide el operador.
 *
 * Es **clave-valor y no una fila por organización con once columnas**, porque
 * una clave ausente tiene que poder caer a su valor por defecto sin migración.
 * Añadir un ajuste nuevo es escribir su default en código, no alterar una
 * tabla; y una organización que nunca tocó un ajuste no ocupa una fila con un
 * valor copiado que quedaría congelado si el default cambiase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('key', 64);

            // JSON porque los ajustes son enteros, decimales, booleanos,
            // cadenas y listas (`reminder_offsets_days`).
            $table->json('value');

            // Quién lo cambió la última vez. `nullOnDelete` conserva el ajuste
            // aunque el usuario desaparezca: el valor sigue siendo válido.
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // `REQ-ORG-002` RB-3: el índice único de una tabla del tenant
            // incluye `organization_id`. Dos organizaciones tienen cada una su
            // `exchange_rate_source` sin saber la una de la otra.
            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
