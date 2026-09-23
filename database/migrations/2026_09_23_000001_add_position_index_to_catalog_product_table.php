<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El índice del orden curado (`REQ-CAT-021` SP-7).
 *
 * La parrilla pública lee los productos de un catálogo **ordenados por
 * `position`**, doce a partir de un cursor (`T-033`). `T-024` dejó el pivote
 * con su clave primaria `(catalog_id, product_id)` y el índice del feed
 * `(catalog_id, created_at)`, pero no con el que SP-7 nombra: sin él, cada
 * lote de un catálogo de quinientos productos ordena en memoria las
 * quinientas filas para devolver doce. Con él, el cursor es una lectura por
 * rango.
 *
 * Va en su propia migración y no editando la de `T-024`, que ya se ejecutó:
 * reescribir una migración aplicada deja una base con un esquema que ningún
 * archivo describe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_product', function (Blueprint $table): void {
            $table->index(['catalog_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_product', function (Blueprint $table): void {
            $table->dropIndex(['catalog_id', 'position']);
        });
    }
};
