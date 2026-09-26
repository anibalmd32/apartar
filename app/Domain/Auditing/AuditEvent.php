<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

/**
 * Qué se audita en Apartar: la **lista cerrada** de `REQ-BOF-005` RB-1.
 *
 * ## Por qué se escribe entera aunque falten treinta emisores
 *
 * RB-1 no enumera lo que hoy se puede registrar: enumera **la decisión de qué
 * merece quedar registrado en este producto**. Repartir esa decisión en seis
 * hitos la convertiría en seis decisiones por tomar, y cada una se tomaría con
 * la mitad del contexto y sin nadie mirando la lista completa. Que un evento no
 * tenga todavía quién lo emita no lo hace menos real: lo hace pendiente.
 *
 * ## Y cómo se evita que un pendiente se quede olvidado
 *
 * Esa es la parte difícil, y una lista de «por conectar» escrita a mano no la
 * resuelve: se queda vieja en silencio, que es exactamente lo que la convención
 * de [06 · Cómo leer este plan] no acepta. Lo que sí funciona es que **cada
 * caso declare la clase que lo emite** ({@see self::emitter()}) y que un test
 * recorra el enum exigiendo que toda clase **que ya existe** use
 * `LogsSensitiveActivity` y nombre su evento.
 *
 * El día que `T-032` cree `PublishCatalogAction`, ese test empieza a cubrirla
 * **sin que nadie tenga que acordarse de nada**: la tarea no puede cerrar en
 * verde con la clase escrita y sin auditar. Y mientras no exista, no hay nada
 * que afirmar sobre ella — no se salta una comprobación, es que todavía no hay
 * sujeto. `AuditEventTest` fija además un mínimo de emisores cubiertos, para
 * que el mecanismo no pueda volverse vacío sin que se note.
 *
 * La declaración va en el enum y no en la `Action` por la dirección en que se
 * lee: aquí se ve de un vistazo qué falta por conectar y de qué tarea es; con
 * el dato en cada Action, saberlo exigiría abrir treinta archivos que aún no
 * existen.
 *
 * ## Qué bloques tienen ya emisor
 *
 * Organización entero —equipo (`T-013`, `T-013b`), ajustes e identidad
 * (`T-014`) y cuentas de cobro (`T-015`)—, Catálogo entero (`T-027`, `T-032`)
 * y, de Clientes, los dos que salen de reutilizar una ficha (`T-043`). Los
 * demás los conecta cada hito con sus propias Actions, que es cuando existen
 * las operaciones que auditar.
 */
enum AuditEvent: string
{
    // -------------------------------------------------------------------
    // Cobranza · H3 y H4
    // -------------------------------------------------------------------

    case ReceiptApproved = 'receipt.approved';
    case ReceiptRejected = 'receipt.rejected';
    case PaymentRecorded = 'payment.recorded';
    case PaymentReallocated = 'payment.reallocated';
    case LayawayCancelled = 'layaway.cancelled';
    case InstallmentRescheduled = 'installment.rescheduled';
    case LayawayDelivered = 'layaway.delivered';

    // -------------------------------------------------------------------
    // Catálogo · H2
    // -------------------------------------------------------------------

    case ProductPricingChanged = 'product.pricing_changed';
    case ProductStatusChanged = 'product.status_changed';
    case CatalogPublished = 'catalog.published';
    case CatalogUnpublished = 'catalog.unpublished';
    case CatalogExpirationScheduled = 'catalog.expiration_scheduled';

    // -------------------------------------------------------------------
    // Clientes · H3 y H6
    // -------------------------------------------------------------------

    case CustomerMerged = 'customer.merged';
    case CustomerIdentityConflict = 'customer.identity_conflict';
    case CustomerContactUpdated = 'customer.contact_updated';
    case AccountLinked = 'account.linked';

    // -------------------------------------------------------------------
    // Organización · H0 · los únicos con emisor hoy
    // -------------------------------------------------------------------

    case SettingsUpdated = 'organization.settings_updated';
    case IdentityUpdated = 'organization.identity_updated';
    case PaymentAccountCreated = 'payment_account.created';
    case PaymentAccountUpdated = 'payment_account.updated';
    case PaymentAccountRemoved = 'payment_account.removed';
    case TeamMemberInvited = 'team_member.invited';
    case TeamInvitationRevoked = 'team_member.invitation_revoked';
    case TeamMemberRoleChanged = 'team_member.role_changed';
    case TeamMemberEnabled = 'team_member.enabled';
    case TeamMemberDisabled = 'team_member.disabled';

    // -------------------------------------------------------------------
    // Suscripción · H5
    // -------------------------------------------------------------------

    case SubscriptionPlanChanged = 'subscription.plan_changed';
    case SubscriptionInvoiceIssued = 'subscription.invoice_issued';
    case SubscriptionInvoicePaid = 'subscription.invoice_paid';
    case SubscriptionDowngradeApplied = 'subscription.downgrade_applied';
    case SubscriptionSuspended = 'subscription.suspended';
    case SubscriptionReactivated = 'subscription.reactivated';

    // -------------------------------------------------------------------
    // Plataforma · H8
    // -------------------------------------------------------------------

    case SupportAccessGranted = 'platform.support_access_granted';
    case SupportAccessRevoked = 'platform.support_access_revoked';
    case PlatformSubscriptionOverridden = 'platform.subscription_overridden';

    /**
     * Bloque de RB-1 al que pertenece el evento.
     */
    public function area(): AuditArea
    {
        return match ($this) {
            self::ReceiptApproved, self::ReceiptRejected, self::PaymentRecorded,
            self::PaymentReallocated, self::LayawayCancelled,
            self::InstallmentRescheduled, self::LayawayDelivered => AuditArea::Collections,

            self::ProductPricingChanged, self::ProductStatusChanged, self::CatalogPublished,
            self::CatalogUnpublished, self::CatalogExpirationScheduled => AuditArea::Catalog,

            self::CustomerMerged, self::CustomerIdentityConflict,
            self::CustomerContactUpdated, self::AccountLinked => AuditArea::Customers,

            self::SettingsUpdated, self::IdentityUpdated, self::PaymentAccountCreated,
            self::PaymentAccountUpdated, self::PaymentAccountRemoved, self::TeamMemberInvited,
            self::TeamInvitationRevoked, self::TeamMemberRoleChanged, self::TeamMemberEnabled,
            self::TeamMemberDisabled => AuditArea::Organization,

            self::SubscriptionPlanChanged, self::SubscriptionInvoiceIssued,
            self::SubscriptionInvoicePaid, self::SubscriptionDowngradeApplied,
            self::SubscriptionSuspended, self::SubscriptionReactivated => AuditArea::Subscription,

            self::SupportAccessGranted, self::SupportAccessRevoked,
            self::PlatformSubscriptionOverridden => AuditArea::Platform,
        };
    }

    /**
     * Clase que emite este evento.
     *
     * **Es el mecanismo del que habla el docblock de arriba, no documentación.**
     * `AuditEventTest` recorre el enum y, para cada clase que ya existe, exige
     * que use `LogsSensitiveActivity` y que nombre su evento. Las que aún no
     * existen no se comprueban porque no hay nada que comprobar todavía — y
     * empiezan a comprobarse **solas** en cuanto su tarea las escriba.
     *
     * Los nombres salen de [06-plan-de-tareas.md] y de la superficie esperada
     * de cada requisito, no de una invención: si una tarea futura decide otro
     * nombre, cambia esta línea y el mecanismo la sigue.
     *
     * Varios eventos comparten emisor cuando la operación es la misma en tres
     * momentos —invitar y revocar viven en `InviteTeamMemberAction` desde
     * `T-013`— y eso no es una imprecisión: es dónde está el código.
     *
     * **Son cadenas y no `::class`, y es justo por lo que esto existe.** PHPStan
     * resuelve `::class` en tiempo de análisis y falla con una clase que
     * todavía no existe, que es el estado normal de treinta de estas treinta y
     * cinco líneas. Escribirlas como `::class` obligaría a añadirlas de una en
     * una según fueran llegando, es decir, a tener la lista incompleta durante
     * setenta tareas — exactamente lo que se quería evitar. `AuditEventTest`
     * comprueba la forma de cada cadena para que un nombre mal escrito no pase
     * por «esa clase aún no existe».
     */
    public function emitter(): string
    {
        return match ($this) {
            // Cobranza · T-053, T-061, T-062, T-063.
            self::ReceiptApproved => 'App\Actions\Payment\ApproveReceiptAction',
            self::ReceiptRejected => 'App\Actions\Payment\RejectReceiptAction',
            self::PaymentRecorded => 'App\Actions\Payment\RegisterManualPaymentAction',
            self::PaymentReallocated => 'App\Actions\Payment\ReallocatePaymentAction',
            self::LayawayCancelled => 'App\Actions\Layaway\CancelLayawayAction',
            self::InstallmentRescheduled => 'App\Actions\Layaway\RescheduleInstallmentAction',
            self::LayawayDelivered => 'App\Actions\Layaway\MarkDeliveredAction',

            // Catálogo · T-027, T-030, T-032. La expiración **no tiene job**
            // (`REQ-CAT-022` RB-6: se deriva en la consulta), así que lo que
            // se audita es el momento en que alguien la programa, que ocurre
            // al publicar.
            self::ProductPricingChanged => 'App\Actions\Product\UpsertProductAction',
            self::ProductStatusChanged => 'App\Actions\Product\ChangeProductStatusAction',
            self::CatalogPublished, self::CatalogExpirationScheduled => 'App\Actions\Catalog\PublishCatalogAction',
            self::CatalogUnpublished => 'App\Actions\Catalog\UnpublishCatalogAction',

            // Clientes · T-043, T-065, T-077. Los dos de `T-043` salen de la
            // misma reutilización de la ficha: la sobrescritura siempre, y el
            // conflicto además cuando el nombre no se parece en nada al que
            // había.
            self::CustomerMerged => 'App\Actions\Customer\MergeCustomersAction',
            self::CustomerIdentityConflict,
            self::CustomerContactUpdated => 'App\Actions\Customer\ResolveCustomerAction',
            self::AccountLinked => 'App\Actions\Account\LinkAccountAction',

            // Organización · T-013, T-013b, T-014, T-015. Los únicos que ya
            // existen, y por tanto los únicos que el test cubre hoy.
            self::SettingsUpdated => 'App\Actions\Settings\UpdateSettingsAction',
            self::IdentityUpdated => 'App\Actions\Organization\UpdateOrganizationAction',
            self::PaymentAccountCreated,
            self::PaymentAccountUpdated,
            self::PaymentAccountRemoved => 'App\Actions\Organization\UpsertPaymentAccountAction',
            self::TeamMemberInvited,
            self::TeamInvitationRevoked => 'App\Actions\Organization\InviteTeamMemberAction',
            self::TeamMemberRoleChanged => 'App\Actions\Organization\ChangeTeamMemberRoleAction',
            self::TeamMemberEnabled => 'App\Actions\Organization\EnableTeamMemberAction',
            self::TeamMemberDisabled => 'App\Actions\Organization\DisableTeamMemberAction',

            // Suscripción · T-068, T-069, T-071, T-072.
            self::SubscriptionPlanChanged => 'App\Actions\Subscription\ChangePlanAction',
            self::SubscriptionInvoiceIssued => 'App\Actions\Subscription\IssueInvoiceAction',
            self::SubscriptionInvoicePaid => 'App\Actions\Subscription\ApproveSubscriptionReceiptAction',
            self::SubscriptionDowngradeApplied => 'App\Actions\Subscription\ApplyDowngradeAction',
            self::SubscriptionSuspended => 'App\Actions\Organization\SuspendOrganizationAction',
            self::SubscriptionReactivated => 'App\Actions\Organization\ReactivateOrganizationAction',

            // Plataforma · T-086.
            self::SupportAccessGranted => 'App\Actions\Platform\GrantSupportAccessAction',
            self::SupportAccessRevoked => 'App\Actions\Platform\RevokeSupportAccessAction',
            self::PlatformSubscriptionOverridden => 'App\Actions\Platform\GrantCourtesyPeriodAction',
        };
    }

    /**
     * Qué pasó, en la frase que lee el dueño del negocio.
     *
     * Va en pasado y sin sujeto: la entrada ya trae quién y sobre qué, y
     * repetirlo aquí produciría la columna de descripciones ilegible en la que
     * cada fila empieza igual.
     */
    public function label(): string
    {
        return match ($this) {
            self::ReceiptApproved => 'Comprobante aprobado',
            self::ReceiptRejected => 'Comprobante rechazado',
            self::PaymentRecorded => 'Pago registrado a mano',
            self::PaymentReallocated => 'Pago reasignado entre cuotas',
            self::LayawayCancelled => 'Apartado cancelado',
            self::InstallmentRescheduled => 'Cuota reprogramada',
            self::LayawayDelivered => 'Apartado marcado como entregado',

            self::ProductPricingChanged => 'Condiciones de pago del producto cambiadas',
            self::ProductStatusChanged => 'Estado del producto cambiado',
            self::CatalogPublished => 'Catálogo publicado',
            self::CatalogUnpublished => 'Catálogo despublicado',
            self::CatalogExpirationScheduled => 'Fecha de expiración del catálogo cambiada',

            self::CustomerMerged => 'Fichas de cliente fusionadas',
            self::CustomerIdentityConflict => 'Conflicto de identidad detectado',
            self::CustomerContactUpdated => 'Ficha de cliente sobrescrita desde el formulario de apartado',
            self::AccountLinked => 'Cuenta de consumidor vinculada a una ficha',

            self::SettingsUpdated => 'Ajustes del negocio cambiados',
            self::IdentityUpdated => 'Identidad del negocio cambiada',
            self::PaymentAccountCreated => 'Cuenta de cobro creada',
            self::PaymentAccountUpdated => 'Cuenta de cobro editada',
            self::PaymentAccountRemoved => 'Cuenta de cobro retirada',
            self::TeamMemberInvited => 'Invitación al equipo enviada',
            self::TeamInvitationRevoked => 'Invitación al equipo revocada',
            self::TeamMemberRoleChanged => 'Rol de un miembro cambiado',
            self::TeamMemberEnabled => 'Acceso de un miembro reactivado',
            self::TeamMemberDisabled => 'Acceso de un miembro retirado',

            self::SubscriptionPlanChanged => 'Plan cambiado',
            self::SubscriptionInvoiceIssued => 'Factura emitida',
            self::SubscriptionInvoicePaid => 'Factura pagada',
            self::SubscriptionDowngradeApplied => 'Degradación de plan aplicada',
            self::SubscriptionSuspended => 'Suscripción suspendida',
            self::SubscriptionReactivated => 'Suscripción reactivada',

            self::SupportAccessGranted => 'Acceso de soporte de Apartar concedido',
            self::SupportAccessRevoked => 'Acceso de soporte de Apartar revocado',
            self::PlatformSubscriptionOverridden => 'Apartar modificó la suscripción de este negocio',
        };
    }
}
