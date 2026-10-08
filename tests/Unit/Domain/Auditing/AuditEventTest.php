<?php

declare(strict_types=1);

use App\Domain\Auditing\AuditArea;
use App\Domain\Auditing\AuditEvent;

/**
 * La lista cerrada de `REQ-BOF-005` RB-1 y —lo que de verdad importa— **el
 * mecanismo que impide que un evento sin emisor se quede olvidado**.
 *
 * El enum declara los treinta y seis eventos y, en cada uno, la clase que lo
 * emite. Treinta de esas clases todavía no existen: llegan con su hito. Una
 * lista de «pendientes por conectar» escrita a mano se habría quedado vieja en
 * silencio, así que lo que hay es esto — un test que **empieza a cubrir cada
 * evento solo, en cuanto su tarea escriba la Action**.
 */

/**
 * Función auxiliar: el código fuente de una clase, si existe.
 */
function sourceOfEmitter(string $class): ?string
{
    if (! class_exists($class)) {
        return null;
    }

    $file = (new ReflectionClass($class))->getFileName();

    return $file === false ? null : (string) file_get_contents($file);
}

it('declara los eventos de los seis bloques de RB-1', function (): void {
    foreach (AuditArea::cases() as $area) {
        expect($area->events())->not->toBeEmpty("El bloque {$area->value} de RB-1 se quedó sin eventos.");
    }

    // Todo evento cae en un bloque: `area()` es un `match` exhaustivo, así que
    // uno nuevo sin bloque decidido lanza en vez de desaparecer del filtro.
    $grouped = array_merge(...array_map(fn (AuditArea $area): array => $area->events(), AuditArea::cases()));

    expect($grouped)->toHaveCount(count(AuditEvent::cases()));
});

/**
 * **El mecanismo, en un test.**
 *
 * Para cada evento cuya clase emisora **ya existe**: tiene que usar
 * `LogsSensitiveActivity` y tiene que nombrar su caso. Las que aún no existen
 * no se comprueban —no hay nada que comprobar— y pasan a comprobarse el día que
 * su tarea las escriba, sin que nadie se acuerde de nada.
 *
 * Se mira también que **nombre el caso** y no solo que use el trait: una Action
 * puede auditar un evento y olvidarse del otro que declara, y eso es
 * exactamente lo que pasaría con `UpsertPaymentAccountAction`, que emite tres.
 */
it('exige que toda Action ya escrita audite el evento que declara', function (AuditEvent $event): void {
    $source = sourceOfEmitter($event->emitter());

    if ($source === null) {
        // Su tarea todavía no ha llegado. No se salta una comprobación: es que
        // no hay sujeto sobre el que afirmar nada.
        expect(true)->toBeTrue();

        return;
    }

    expect(str_contains($source, 'LogsSensitiveActivity'))->toBeTrue(sprintf(
        '%s emite %s y no usa LogsSensitiveActivity.',
        $event->emitter(),
        $event->value,
    ));

    expect(str_contains($source, 'AuditEvent::'.$event->name))->toBeTrue(sprintf(
        '%s existe y no emite %s. Añade la llamada a audit() o cambia el emisor en AuditEvent.',
        $event->emitter(),
        $event->value,
    ));
})->with(AuditEvent::cases());

/**
 * Y la otra mitad: el mecanismo **no puede volverse vacío sin que se note**.
 *
 * Si mañana alguien renombrara `UpsertPaymentAccountAction` y se olvidara de
 * esta lista, el test de arriba pasaría en verde sobre cero clases. Este fija
 * el suelo, y **el suelo sube con cada tarea que escribe su Action**: a los
 * diez del bloque Organización —`T-013`, `T-013b`, `T-014`, `T-015`— `T-027`
 * añade los dos de Catálogo que ya tienen clase, `ProductPricingChanged` y
 * `ProductStatusChanged`, y `T-032` cierra ese bloque con los tres de
 * catálogos: `CatalogPublished` y `CatalogExpirationScheduled` desde
 * `PublishCatalogAction`, y `CatalogUnpublished` desde
 * `UnpublishCatalogAction`. `T-043` abre el de Clientes con los dos de
 * `ResolveCustomerAction`: `CustomerContactUpdated` y
 * `CustomerIdentityConflict`. `T-053` abre el de Cobranza con tres:
 * `LayawayCancelled`, `LayawayDelivered` e `InstallmentRescheduled`, cada uno
 * desde su Action; `T-061` le suma `ReceiptApproved`, desde
 * `ApproveReceiptAction`, y `T-062` `ReceiptRejected` y `PaymentRecorded`,
 * desde `RejectReceiptAction` y `RegisterManualPaymentAction`; `T-063`
 * `PaymentReallocated`, desde `ReallocatePaymentAction`; y `T-064`
 * `ReconciliationCorrected`, desde `ReconcileLayawaysAction`.
 *
 * Que la cifra haya que tocarla al añadir un emisor es el punto: obliga a
 * mirar si la Action nueva audita de verdad, en vez de dejar que el conteo se
 * ajuste solo.
 */
it('cubre hoy los veinticinco eventos cuya Action ya existe', function (): void {
    $covered = array_values(array_filter(
        AuditEvent::cases(),
        fn (AuditEvent $event): bool => class_exists($event->emitter()),
    ));

    expect($covered)->toHaveCount(25);

    $areas = array_values(array_unique(
        array_map(fn (AuditEvent $event): string => $event->area()->value, $covered),
    ));

    expect($areas)->toEqualCanonicalizing([
        AuditArea::Organization->value,
        AuditArea::Catalog->value,
        AuditArea::Customers->value,
        AuditArea::Collections->value,
    ]);
});

/**
 * La lista cerrada de RB-1 tiene treinta y seis casos: `T-043` añadió
 * `customer.contact_updated` y `T-064` `reconciliation.corrected`, cada uno con
 * su entrada en la spec. Crecer es una
 * decisión de [30] y no de un enum, así que la cifra se afirma.
 */
it('declara los treinta y seis eventos de la lista cerrada', function (): void {
    expect(AuditEvent::cases())->toHaveCount(36);
    expect(AuditEvent::CustomerContactUpdated->value)->toBe('customer.contact_updated');
});

/**
 * Un emisor mal escrito no puede colarse como «esa clase aún no existe».
 *
 * Es el punto débil evidente de declarar clases como cadenas: `App\Acions\…`
 * con una errata se comportaría igual que una tarea pendiente y nadie lo
 * notaría hasta H4. La forma sí se puede comprobar hoy.
 */
it('declara cada emisor como una Action con espacio de nombres propio', function (AuditEvent $event): void {
    expect($event->emitter())
        ->toStartWith('App\\Actions\\')
        ->toEndWith('Action')
        ->toMatch('/^App\\\\Actions\\\\[A-Z][A-Za-z]+\\\\[A-Z][A-Za-z]+Action$/');
})->with(AuditEvent::cases());

it('nombra cada evento para una persona', function (AuditEvent $event): void {
    expect($event->label())->not->toBe('')->and($event->label())->not->toBe($event->value);
})->with(AuditEvent::cases());

/**
 * Los valores son estables: se guardan en la columna `event` de filas que **no
 * se reescriben** (RB-3). Renombrar uno dejaría ilegible la auditoría anterior
 * al cambio, así que este test existe para que cambiarlos sea una decisión y no
 * un descuido de refactor.
 */
it('mantiene los valores del bloque Organización, que ya hay filas escritas con ellos', function (): void {
    $organization = array_map(
        fn (AuditEvent $event): string => $event->value,
        AuditArea::Organization->events(),
    );

    expect($organization)->toBe([
        'organization.settings_updated',
        'organization.identity_updated',
        'payment_account.created',
        'payment_account.updated',
        'payment_account.removed',
        'team_member.invited',
        'team_member.invitation_revoked',
        'team_member.role_changed',
        'team_member.enabled',
        'team_member.disabled',
    ]);
});
