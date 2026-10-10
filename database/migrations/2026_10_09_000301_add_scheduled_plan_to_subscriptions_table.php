<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La bajada de plan programada (`REQ-SUB-005` RB-3, RB-7, `T-068`).
 *
 * Bajar no es inmediato: el negocio conserva lo que pagó hasta el fin del
 * período, y la degradación se aplica al vencer (`REQ-SUB-008`, `T-072`). Lo
 * que hace falta guardar es **a qué plan** y **desde cuándo**, y por eso son
 * dos columnas propias y no `grace_ends_at`: esa es la gracia del impago
 * (`REQ-SUB-007` RB-1), y una organización con una factura vencida puede
 * además tener una bajada pendiente. Con una sola columna, programar la
 * bajada le movería el plazo de pago, o pagar le movería la bajada.
 *
 * Revertir (RB-7) es vaciar las dos: «sin coste ni rastro operativo».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // `restrictOnDelete` por lo mismo que `plan_id`: un plan que
            // alguien tiene programado no se borra, se despublica.
            $table->foreignId('scheduled_plan_id')->nullable()->after('plan_id')->constrained('plans')->restrictOnDelete();
            $table->timestamp('scheduled_plan_at')->nullable()->after('scheduled_plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn('scheduled_plan_at');
        });
    }
};
