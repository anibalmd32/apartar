<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retira las filas de `storefront_enabled` de `organization_settings`
 * ([02 §9.1], `REQ-ORG-006`).
 *
 * El ajuste estuvo declarado **dos veces** hasta que `T-010` lo detectó: como
 * clave del enum y como columna `organizations.storefront_enabled`. Manda la
 * columna, y el motivo está en [02 §9.1]: la vitrina pública `/{org-slug}` la
 * consulta en **cada petición anónima**, y una columna se filtra en la misma
 * consulta que resuelve la organización, sin pasar por la caché de ajustes ni
 * por su respaldo en base de datos. Encender la vitrina es además identidad
 * del negocio —como el logo o el enlace—, no un parámetro de operación como la
 * tolerancia de liquidación.
 *
 * **Es una migración de datos y no toca el esquema**: `T-010` llegó a sembrar
 * esas filas en toda organización registrada, y dejarlas ahí significaría que
 * la pantalla de ajustes tendría que saber cuáles de las once filas ignorar.
 * `Settings` ya las ignora por su cuenta —una clave que el enum no reconoce se
 * salta (RB-2)—, así que este borrado no es lo que sostiene la corrección: es
 * lo que evita arrastrar para siempre una fila que nadie va a leer, con un
 * valor que puede contradecir a la columna.
 *
 * No tiene `down()` útil y por eso no lo finge: revertir sembraría una clave
 * que este código ya no conoce, y el valor real vive en la columna.
 */
return new class extends Migration
{
    /**
     * Clave retirada. Se escribe literal y no se lee del enum a propósito:
     * el enum ya no la tiene, y una migración que dependiera de un valor que
     * el código de hoy no declara dejaría de funcionar en cuanto alguien
     * renombrara algo.
     */
    private const RETIRED_KEY = 'storefront_enabled';

    public function up(): void
    {
        DB::table('organization_settings')->where('key', self::RETIRED_KEY)->delete();
    }

    public function down(): void
    {
        // Sin vuelta atrás: el valor vigente de la vitrina es la columna
        // `organizations.storefront_enabled`, y resembrar la clave crearía la
        // segunda fuente de verdad que esta migración retira.
    }
};
