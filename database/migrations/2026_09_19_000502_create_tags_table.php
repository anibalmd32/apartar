<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etiquetas del inventario (`REQ-BOF-023`, `REQ-CAT-006`).
 *
 * La otra mitad de la taxonomía, y la blanda: a diferencia de la categoría
 * —que es una y con FK `restrict`—, **una etiqueta se puede borrar en
 * cualquier momento** (RB-5) y simplemente se desasocia de los productos. Por
 * eso su relación con `products` es un pivote con borrado en cascada y la de
 * la categoría no lo es.
 *
 * Sin `position`: las etiquetas no se ordenan a mano. Es la diferencia con
 * `categories`, donde el orden es una decisión del negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->string('slug', 80);

            $table->timestamps();

            // RB-3 · los dos únicos por organización, por lo mismo que en
            // `categories`: RB-8 reutiliza la etiqueta existente al crearla al
            // vuelo desde el formulario de producto, y sin el índice del
            // nombre dos peticiones simultáneas la duplican.
            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
