<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Una entrada del registro de auditoría (`REQ-BOF-005`).
 *
 * Extiende el modelo de `spatie/laravel-activitylog` para añadirle lo único que
 * le falta y que aquí no es negociable: **`BelongsToOrganization`**. La tabla
 * del paquete es global por diseño; la nuestra es del tenant, porque una
 * entrada de auditoría dice quién tocó el dinero de **un** negocio y SP-6 exige
 * que la de otro sea invisible. Con el trait, toda consulta filtra y toda
 * escritura rellena, igual que en cualquier otra tabla del tenant
 * (`REQ-ORG-002` RB-1 y RB-2).
 *
 * Se registra en `config/activitylog.php` como `activity_model`, así que
 * también es el modelo que usaría el helper `activity()` del paquete.
 *
 * **Es append-only** (RB-3): la aplicación no expone edición ni borrado. No hay
 * ruta, ni acción, ni método que lo haga; lo que sostiene la regla es que no
 * exista el código, no una comprobación.
 *
 * ## El actor es polimórfico y tiene que seguir siéndolo
 *
 * `causer_type`/`causer_id` vienen del `nullableMorphs` del paquete, y esa
 * forma ya admite los tres actores de RB-7: un `User` del equipo, un
 * `PlatformUser` bajo grant de soporte (`REQ-PLT-005`, H8) y el sistema, con
 * `causer` nulo. **No se estrecha a `User` con una clave foránea**, y eso es
 * una decisión tomada hoy para que H8 no tenga que migrar la tabla: el día que
 * exista `PlatformUser`, escribir su acción aquí no cambia el esquema.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $event
 * @property string|null $causer_type
 * @property int|null $causer_id
 */
class AuditEntry extends Activity
{
    use BelongsToOrganization;

    /**
     * Nombre de registro de lo que escribe `LogsSensitiveActivity`.
     *
     * Existe para separar nuestras entradas de cualquier otra que el paquete
     * llegue a escribir por su cuenta: la vista de `REQ-BOF-005` enseña la
     * lista cerrada de RB-1 y nada más.
     */
    public const LOG_NAME = 'audit';

    /**
     * Entradas de la auditoría de negocio, de la más reciente a la más
     * antigua.
     *
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeAudited(Builder $query): Builder
    {
        return $query->where('log_name', self::LOG_NAME);
    }

    /**
     * Filtra por uno de los eventos de la lista cerrada.
     *
     * Recibe el enum y no una cadena suelta: el filtro de la pantalla llega
     * como texto del `query string` y quien lo convierte es el controlador, que
     * es donde un valor inventado tiene que rebotar.
     *
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeOfEvent(Builder $query, AuditEvent $event): Builder
    {
        return $query->where('event', $event->value);
    }
}
