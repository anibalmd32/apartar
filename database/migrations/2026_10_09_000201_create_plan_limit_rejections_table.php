<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los apartados que un cliente intentó y el límite del plan rechazó
 * (`REQ-SUB-004` SP-2, `REQ-BOF-044` RB-5, `T-067`).
 *
 * Es la **evidencia de una venta perdida** que exige el aviso
 * `plan_limit_blocking_sales` del panel: sale solo si hay una fila de los
 * últimos siete días, no por estar cerca del techo. Una fila por intento, con
 * su métrica y su fecha, y nada del cliente: lo que el negocio necesita saber
 * es que está perdiendo ventas, no quién se quedó fuera, y guardar su documento
 * sin un apartado que lo justifique sería guardar un dato personal de más.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_limit_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 32);
            $table->timestamp('occurred_at');
            $table->timestamps();

            // Lo que pregunta el panel: «¿hubo alguno en los últimos 7 días?».
            $table->index(['organization_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_limit_rejections');
    }
};
