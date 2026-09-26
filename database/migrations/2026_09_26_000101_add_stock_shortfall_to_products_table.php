<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las unidades apartadas que no están en existencias (`REQ-LAY-003` SP-2,
 * `T-044`).
 *
 * ## Por qué hace falta una columna, si las reservadas son calculadas
 *
 * Las reservadas **se derivan** de los apartados no terminales, y por eso no
 * tienen columna: una segunda cuenta del mismo hecho podría discrepar. El
 * faltante **no se deriva de nada**. Aparece cuando el negocio declara menos
 * existencias de las que ya tiene apartadas —3 apartadas y 1 en la tienda—, y
 * `stock`, que son las disponibles y no puede ser negativo (RB-4), se queda en
 * cero: la diferencia no cabe en ningún otro sitio.
 *
 * Perderla no es un problema de pantalla. Cuando se cancele uno de esos tres
 * apartados, la liberación sumaría una unidad a `stock` que **no existe**, y el
 * siguiente cliente apartaría una nevera fantasma. Con el faltante guardado,
 * la liberación lo absorbe primero y solo devuelve a la venta lo que de verdad
 * está (`ReleaseStockAction`).
 *
 * Sin columna del tenant propia: es una columna más de `products`, que ya la
 * tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('stock_shortfall')->default(0)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('stock_shortfall');
        });
    }
};
