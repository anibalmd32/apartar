<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo único que escribe en `activity_log` (`REQ-BOF-005` RB-2, RB-3).
 *
 * Es deliberadamente tonto: recibe una entrada ya compuesta y la inserta. Toda
 * la decisión —qué actor, qué sujeto, qué cambió, qué se redacta— vive en
 * `LogsSensitiveActivity` y en `AuditProperties`, que es donde se puede leer
 * junto a su porqué.
 *
 * ## Por qué es una clase y no dos líneas dentro del trait
 *
 * Por lo mismo que `PublishedCatalogs` y `RecordedReceipts` son clases: para
 * poder **sustituirla en el contenedor**. RB-4 dice que un fallo al escribir la
 * auditoría no puede romper la operación, y esa es de las reglas que solo
 * cuentan si están probadas — con la escritura empotrada en el trait, la única
 * forma de provocar un fallo sería romper la base de datos a mitad de un test,
 * que en MySQL se lleva por delante el aislamiento de `RefreshDatabase`.
 *
 * Por eso no es `final`: `AuditTrailDegradationTest` la sustituye por una que
 * revienta y comprueba que la operación de negocio sobrevive igual.
 *
 * `organization_id` no se asigna aquí: lo rellena `BelongsToOrganization` desde
 * el tenant activo, como en cualquier otra tabla del tenant (`REQ-ORG-002`
 * RB-2). Escribirlo a mano sería la puerta por la que una entrada acabaría en
 * la organización equivocada.
 */
class AuditTrail
{
    /**
     * Inserta la entrada.
     *
     * @param  Model|null  $subject  sobre qué se actuó
     * @param  Model|null  $causer  quién actuó; nulo es el sistema (RB-7)
     * @param  array<string, mixed>  $properties  antes y después, ya saneados
     */
    public function record(
        AuditEvent $event,
        string $description,
        ?Model $subject,
        ?Model $causer,
        array $properties,
    ): void {
        AuditEntry::query()->create([
            'log_name' => AuditEntry::LOG_NAME,
            'event' => $event->value,
            'description' => $description,

            // `getMorphClass()` y no `::class`: si algún día hay alias de morph
            // registrados, la columna tiene que guardar el alias, que es lo que
            // el `morphTo` sabrá resolver después.
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),

            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),

            'properties' => $properties,
        ]);
    }
}
