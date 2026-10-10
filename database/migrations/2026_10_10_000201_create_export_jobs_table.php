<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las exportaciones a Excel (`REQ-BOF-043`, `T-070`).
 *
 * Una fila por petición: qué se exporta, con qué filtros —los que la pantalla
 * **aplicó**, guardados para rehidratarlos en el job (RB-3)—, por dónde va y,
 * al terminar, dónde quedó el archivo y cuánto pesa. El peso cuenta en
 * `storage_mb` mientras la exportación esté vigente (`REQ-SUB-003` RB-2), y
 * deja de contar al purgarse a los siete días (RB-7).
 *
 * Sin único por tipo en curso (RB-10): un único no puede decir «en curso».
 * Lo decide la acción con una lectura bloqueante sobre el índice
 * `(organization_id, type, status)` y reintentando si dos se cruzan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 20);
            $table->string('status', 20)->default('queued');
            $table->json('filters');

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // RB-10 y la lista de la pantalla.
            $table->index(['organization_id', 'type', 'status']);
            // La purga (RB-7).
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
