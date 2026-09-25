<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién modificó por última vez un producto o un catálogo (`REQ-BOF-020`
 * SP-7, `REQ-CAT-023` SP-4, `T-038`).
 *
 * Las dos reglas piden la marca con su autor y ninguna tabla tenía dónde
 * guardarlo: `T-027` y `T-031` dejaron la fecha y el autor sin resolver.
 * Nullable porque las filas que ya existen no tienen autor conocido, y
 * `nullOnDelete` porque un miembro del equipo no se borra (`REQ-ORG-005`
 * RB-1) pero una invitación revocada sí, y su rastro no puede tumbar el
 * producto que llegó a tocar.
 *
 * Sin índice propio: nadie filtra por autor. La clave foránea ya crea el
 * suyo.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'catalogs'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->foreignId('updated_by')->nullable()->after('updated_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['products', 'catalogs'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('updated_by');
            });
        }
    }
};
