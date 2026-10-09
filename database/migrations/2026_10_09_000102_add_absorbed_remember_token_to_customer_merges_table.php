<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El «recordarme» del portal de la ficha absorbida (`REQ-IDN-004` SP-2,
 * `T-065`).
 *
 * La cookie de recuerdo lleva el id de la ficha y su `remember_token`. Al
 * borrarse la absorbida, ese token desaparece con ella y, cuando la sesión
 * caduca, la persona quedaría fuera. Se guarda en su fusión para que la cookie
 * siga llevando a la superviviente, comprobada contra el mismo token: tener el
 * id de una ficha absorbida no basta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_merges', function (Blueprint $table): void {
            $table->string('absorbed_remember_token', 100)->nullable()->after('absorbed_document_number');
        });
    }

    public function down(): void
    {
        Schema::table('customer_merges', function (Blueprint $table): void {
            $table->dropColumn('absorbed_remember_token');
        });
    }
};
