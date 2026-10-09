<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las fusiones de fichas (`REQ-IDN-006`, `T-065`): qué ficha absorbió a cuál.
 *
 * La ficha absorbida se **borra** (RB-5), y aun así tres cosas necesitan saber
 * adónde fue:
 *
 *  - su URL del backoffice redirige a la superviviente (`REQ-BOF-041` SP-3);
 *  - una sesión del portal abierta con ella se reapunta a la superviviente
 *    (`REQ-IDN-004` SP-2, SP-3);
 *  - su documento, con el código de un apartado que ahora es de la
 *    superviviente, sigue abriendo el portal (`REQ-IDN-008` SP-6).
 *
 * Por eso se guarda el id y el documento de la absorbida, que ya no existen
 * en `customers`. Sin clave ajena hacia ella, porque no está; hacia la
 * superviviente, `cascade`: si algún día se borra, su historial de fusiones no
 * tiene a quién apuntar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_merges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('absorbed_customer_id');
            $table->string('absorbed_document_number', 20);
            $table->foreignId('survivor_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Una ficha se absorbe una vez: después ya no existe.
            $table->unique(['organization_id', 'absorbed_customer_id']);
            $table->index(['organization_id', 'absorbed_document_number']);
            $table->index(['organization_id', 'survivor_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_merges');
    }
};
