<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Auditing\AuditProperties;
use App\Domain\Auditing\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Deja constancia de quién hizo qué y cuándo (`REQ-BOF-005`).
 *
 * Lo usan las `Action` que ejecutan algo de la lista cerrada de RB-1. Cada
 * evento declara su emisor en `AuditEvent::emitter()`, y `AuditEventTest`
 * comprueba que toda clase declarada **que ya existe** use este trait: es lo
 * que impide que una tarea futura escriba su `Action` y se olvide de auditar.
 *
 * ## La regla que gobierna este archivo entero
 *
 * **Escribir la auditoría no puede romper la operación** (RB-4, SP-3). Es la
 * inversión deliberada del fallo ruidoso que rige el resto del proyecto, y el
 * motivo es concreto y no discutible: perder el registro de que se aprobó un
 * comprobante es malo; **no aprobar el comprobante porque el registro falló es
 * peor** — el dinero ya se movió en el banco del cliente—. Así que aquí todo se
 * degrada:
 *
 *  1. si `properties` no se puede componer o no cabe, se guarda su resumen
 *     (`AuditProperties`);
 *  2. si la inserción falla, se reintenta **sin `properties`**, que es la
 *     entrada mínima: quién, qué y cuándo;
 *  3. si eso también falla, se reporta y se sigue.
 *
 * Los tres fallos van a `report()`, es decir al log de errores, que es donde
 * alguien los ve. Degradar en silencio sería cambiar un problema ruidoso por
 * uno invisible, y eso no es lo que RB-4 pide.
 *
 * ## Los tres actores (RB-7)
 *
 * `$actor` es un `Model` y no un `User`, y es lo que permite los tres casos sin
 * tocar el esquema: un `User` del equipo, un `PlatformUser` bajo grant de
 * soporte (RB-6, H8) y **el sistema**, que es `$actor` nulo con el nombre del
 * proceso en la descripción (SP-1). Un job que expira apartados no tiene
 * usuario y la entrada tiene que decir qué lo hizo, no quedarse en blanco.
 *
 * ## Y de dónde sale la organización
 *
 * De ningún parámetro: de `TenantContext`, a través de `BelongsToOrganization`
 * en `AuditEntry`. Una `Action` no puede elegir en qué organización deja su
 * rastro, que es justamente lo que hace cierto el aislamiento de SP-6.
 */
trait LogsSensitiveActivity
{
    /**
     * Registra un evento de la lista cerrada.
     *
     * @param  Model|null  $subject  sobre qué se actuó; nulo cuando el sujeto es la propia organización
     * @param  Model|null  $actor  quién lo hizo; nulo es el sistema (RB-7, SP-1)
     * @param  array<string, mixed>  $before  estado relevante antes (RB-2)
     * @param  array<string, mixed>  $after  estado relevante después (RB-2)
     * @param  string|null  $process  nombre del proceso cuando no hay actor (SP-1)
     */
    protected function audit(
        AuditEvent $event,
        ?Model $subject = null,
        ?Model $actor = null,
        array $before = [],
        array $after = [],
        ?string $process = null,
    ): void {
        $description = $this->auditDescription($event, $subject, $actor, $process);

        try {
            $properties = AuditProperties::between($before, $after)->toArray();
        } catch (Throwable $exception) {
            // Componer el diff no debería lanzar —`AuditProperties` ya degrada
            // lo que no se deja serializar—, pero si lo hiciera, la entrada
            // sigue saliendo sin detalle en vez de tumbar la operación.
            report($exception);

            $properties = [];
        }

        try {
            $this->auditTrail()->record($event, $description, $subject, $actor, $properties);
        } catch (Throwable $exception) {
            report($exception);

            $this->recordMinimalAuditEntry($event, $description, $subject, $actor);
        }
    }

    /**
     * Segundo intento, sin `properties`: la entrada mínima de RB-4.
     *
     * Merece la pena por lo que conserva. Aunque el detalle se pierda, queda
     * **quién, qué y cuándo**, que es lo que se consulta cuando un cliente
     * reclama; renunciar a la fila entera porque un campo no cupo sería tirar
     * la respuesta con la pregunta.
     */
    private function recordMinimalAuditEntry(AuditEvent $event, string $description, ?Model $subject, ?Model $actor): void
    {
        try {
            $this->auditTrail()->record($event, $description, $subject, $actor, []);
        } catch (Throwable $exception) {
            // Aquí se acaba: la operación de negocio continúa y el fallo queda
            // en el log de errores. Volver a intentarlo sería pedirle tres
            // veces lo mismo a algo que ya dijo que no dos.
            report($exception);
        }
    }

    /**
     * La frase de la entrada: qué pasó, sobre qué y —si no hubo persona— qué
     * proceso lo hizo.
     *
     * El sujeto se nombra **aquí y ahora** y no al pintarlo, y eso es SP-5: una
     * ficha archivada o un miembro dado de baja siguen dejando la entrada
     * legible, porque el nombre que tenían quedó escrito en el momento en que
     * el hecho ocurrió. Resolver el `morphTo` al leer daría «(eliminado)» justo
     * en la consulta que importa.
     */
    private function auditDescription(AuditEvent $event, ?Model $subject, ?Model $actor, ?string $process): string
    {
        $description = $event->label();

        $label = $this->auditSubjectLabel($subject);

        if ($label !== null) {
            $description .= ': '.$label;
        }

        if ($actor === null) {
            // SP-1 · sin actor, la descripción dice qué proceso fue. «Sistema»
            // a secas obligaría a adivinar entre catorce jobs.
            $description .= ' · '.($process ?? 'proceso del sistema');
        }

        return $description;
    }

    /**
     * Cómo se nombra el sujeto en la descripción.
     *
     * Se prueban los atributos con los que un humano reconoce una fila, en el
     * orden en que los reconoce, y si no hay ninguno se cae al identificador.
     * Un `Producto #418` es peor que «Taladro Bosch» y mucho mejor que nada:
     * con él, quien lee la auditoría puede ir a buscar la fila.
     */
    private function auditSubjectLabel(?Model $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }

        // `full_name` es el de la ficha de cliente, y va antes que `email`: el
        // correo es opcional y es justo uno de los datos que la reutilización
        // de la ficha puede sobrescribir.
        foreach (['name', 'full_name', 'title', 'code', 'email', 'identifier'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $key = $subject->getKey();

        return $key === null ? null : class_basename($subject).' #'.$key;
    }

    /**
     * El escritor, resuelto del contenedor.
     *
     * Del contenedor y no con un `new`: es lo que permite sustituirlo por uno
     * que falla y comprobar que la operación de negocio sobrevive (RB-4).
     */
    private function auditTrail(): AuditTrail
    {
        return app(AuditTrail::class);
    }
}
