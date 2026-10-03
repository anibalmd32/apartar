<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El «recuérdame» de la sesión acotada del portal (`REQ-IDN-008` RB-4,
 * `T-049`).
 *
 * RB-4 pide que la sesión abierta con documento + código se recuerde 30 días,
 * y el guard de sesión de Laravel lo hace con un token rotatorio guardado en
 * la fila de quien se autentica. [05 §4] no lo declaraba porque la ficha no
 * tiene contraseña ni la tendrá (`REQ-IDN-001` RB-5): esta columna **no** es
 * una credencial que la persona conozca, es el comprobante de una sesión que
 * ya abrió con su documento y su código. `CustomerSchemaTest` sigue
 * rechazando `password`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->rememberToken()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('remember_token');
        });
    }
};
