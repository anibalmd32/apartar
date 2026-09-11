<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de tasas de cambio (`REQ-FX-002`).
 *
 * Tabla de **plataforma**: la ingesta es única y de la plataforma (`D-6`
 * RB-0), un job cada 30 minutos con independencia de cuántas organizaciones
 * existan. Escalar a 10.000 negocios no multiplica las llamadas a DolarApi.
 *
 * Es un **histórico inmutable**: solo `INSERT` (RB-2). Nunca se actualiza ni
 * se borra una fila.
 *
 * Las tasas **manuales** de una organización no entran aquí (`REQ-FX-003`
 * RB-4): son un dato suyo, no de la plataforma, y su histórico vive en la
 * auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();

            // `RateSource`: bcv | parallel. `manual` nunca llega a esta tabla.
            $table->string('source', 20);

            // DECIMAL(18,8) y jamás coma flotante: la conversión a VES usa
            // `bcmul` con escala 8 (`REQ-FX-004` RB-5).
            $table->decimal('rate', 18, 8);

            // Las dos marcas de tiempo de RB-3b, y no son lo mismo:
            //   published_at  cuándo lo publicó la fuente. Es la antigüedad
            //                 que le importa al negocio y la que dispara las
            //                 alertas de obsolescencia.
            //   fetched_at    cuándo lo leímos nosotros. Solo sirve para
            //                 diagnosticar la ingesta.
            $table->timestamp('published_at');

            // Lo escribe la base de datos y no el proceso PHP, para que un
            // reloj desfasado del contenedor no lo falsee (SP-6).
            $table->timestamp('fetched_at')->useCurrent();

            $table->timestamps();

            // Sobre `published_at`, no sobre `fetched_at`: la consulta que
            // importa es "la última tasa publicada por esta fuente".
            $table->index(['source', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
