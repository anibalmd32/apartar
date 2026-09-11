<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizaciones: el tenant (`REQ-ORG-001`, `REQ-ORG-008`).
 *
 * Esta tabla **no** lleva `organization_id` porque es el tenant, no está
 * sujeta a él. Su `slug` es `UNIQUE` global y es una de las tres excepciones
 * declaradas a la regla de unicidad por organización (`REQ-QAX-008` RB-4); en
 * su caso la excepción es trivial, al no ser una tabla del tenant.
 *
 * `deleted_at` es `SoftDeletes`: el cierre voluntario conserva los datos y la
 * eliminación definitiva es una operación manual de plataforma (`REQ-ORG-008`
 * RB-5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // URL pública por ruta (/{org-slug}), lo que evita DNS comodín en
            // desarrollo (decisión Q4). Editable mientras no haya catálogos
            // publicados (`REQ-ORG-001` RB-2).
            $table->string('slug', 100);

            // RIF. Opcional en el registro y obligatorio solo para emitir la
            // primera factura de suscripción (`REQ-ORG-001` RB-7).
            $table->string('document', 32)->nullable();

            // Toda fecha se persiste en UTC y se presenta en esta zona
            // (`REQ-INF-004` RB-2). También es la que resuelven los comandos
            // programados por organización (`REQ-INF-003` RB-5).
            $table->string('timezone', 64)->default('America/Caracas');

            $table->string('logo_path')->nullable();
            $table->string('brand_color', 7)->nullable();

            // La vitrina en /{org-slug} está desactivada por defecto
            // (`REQ-ORG-006` RB-6): la plataforma no es un directorio.
            $table->boolean('storefront_enabled')->default(false);

            // `OrganizationStatus`: active | suspended | closed.
            $table->string('status', 20)->default('active');

            // Impago prolongado: el equipo pierde el backoffice, pero los
            // apartados en curso y el portal del cliente siguen intactos
            // (`REQ-ORG-008` RB-1, la regla no negociable Q7).
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
