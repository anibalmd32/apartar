<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La ficha técnica de un producto (`REQ-CAT-004`).
 *
 * Filas y no una columna JSON: la ficha se ordena, se agrupa y se lee campo a
 * campo, y una lista de pares en JSON obliga a reescribir el documento entero
 * para mover una línea.
 *
 * `group` es opcional porque una ficha corta no necesita secciones —«Material:
 * acero» no pertenece a ningún grupo— y `position` guarda el orden en que el
 * negocio quiere que se lea, que casi nunca es alfabético.
 *
 * Lleva `organization_id` propio aunque cuelgue de un producto que ya lo
 * tiene: es una tabla del tenant con su modelo (`T-025`), y el trait
 * `BelongsToOrganization` necesita la columna para filtrar sin pasar por el
 * producto. Es la diferencia con los dos pivotes, que no tienen modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_specifications', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // `group` es palabra reservada en MySQL; Laravel la entrecomilla
            // siempre, así que el nombre de [05 §4] se respeta tal cual.
            $table->string('group', 80)->nullable();

            $table->string('label', 120);
            $table->string('value', 500);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // La ficha se lee entera y en orden, siempre de un producto.
            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specifications');
    }
};
