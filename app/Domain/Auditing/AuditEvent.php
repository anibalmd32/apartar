<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

enum AuditEvent: string
{
    case ReceiptApproved = 'receipt.approved';
    case ReceiptRejected = 'receipt.rejected';
    case PaymentRecorded = 'payment.recorded';
    case PaymentReallocated = 'payment.reallocated';
    case LayawayCreated = 'layaway.created';
    case LayawayCancelled = 'layaway.cancelled';
    case InstallmentRescheduled = 'installment.rescheduled';
    case LayawayDelivered = 'layaway.delivered';
    case ReconciliationCorrected = 'reconciliation.corrected';

    case ProductPricingChanged = 'product.pricing_changed';
    case ProductStatusChanged = 'product.status_changed';
    case CatalogPublished = 'catalog.published';
    case CatalogUnpublished = 'catalog.unpublished';
    case CatalogExpirationScheduled = 'catalog.expiration_scheduled';

    case CustomerMerged = 'customer.merged';
    case CustomerIdentityConflict = 'customer.identity_conflict';
    case CustomerContactUpdated = 'customer.contact_updated';
    case AccountLinked = 'account.linked';

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
    case OrganizationClosed = 'organization.closed';

    case SubscriptionPlanChanged = 'subscription.plan_changed';
    case SubscriptionInvoiceIssued = 'subscription.invoice_issued';
    case SubscriptionInvoicePaid = 'subscription.invoice_paid';
    case SubscriptionDowngradeApplied = 'subscription.downgrade_applied';
    case SubscriptionSuspended = 'subscription.suspended';
    case SubscriptionReactivated = 'subscription.reactivated';

    case SupportAccessGranted = 'platform.support_access_granted';
    case SupportAccessRevoked = 'platform.support_access_revoked';
    case PlatformSubscriptionOverridden = 'platform.subscription_overridden';

    public function area(): AuditArea
    {
        return match ($this) {
            self::ReceiptApproved, self::ReceiptRejected, self::PaymentRecorded,
            self::PaymentReallocated, self::LayawayCreated, self::LayawayCancelled,
            self::InstallmentRescheduled, self::LayawayDelivered,
            self::ReconciliationCorrected => AuditArea::Collections,

            self::ProductPricingChanged, self::ProductStatusChanged, self::CatalogPublished,
            self::CatalogUnpublished, self::CatalogExpirationScheduled => AuditArea::Catalog,

            self::CustomerMerged, self::CustomerIdentityConflict,
            self::CustomerContactUpdated, self::AccountLinked => AuditArea::Customers,

            self::SettingsUpdated, self::IdentityUpdated, self::PaymentAccountCreated,
            self::PaymentAccountUpdated, self::PaymentAccountRemoved, self::TeamMemberInvited,
            self::TeamInvitationRevoked, self::TeamMemberRoleChanged, self::TeamMemberEnabled,
            self::TeamMemberDisabled, self::OrganizationClosed => AuditArea::Organization,

            self::SubscriptionPlanChanged, self::SubscriptionInvoiceIssued,
            self::SubscriptionInvoicePaid, self::SubscriptionDowngradeApplied,
            self::SubscriptionSuspended, self::SubscriptionReactivated => AuditArea::Subscription,

            self::SupportAccessGranted, self::SupportAccessRevoked,
            self::PlatformSubscriptionOverridden => AuditArea::Platform,
        };
    }

    public function emitter(): string
    {
        return match ($this) {
            self::ReceiptApproved => 'App\Actions\Payment\ApproveReceiptAction',
            self::ReceiptRejected => 'App\Actions\Payment\RejectReceiptAction',
            self::PaymentRecorded => 'App\Actions\Payment\RegisterManualPaymentAction',
            self::PaymentReallocated => 'App\Actions\Payment\ReallocatePaymentAction',
            self::LayawayCreated => 'App\Actions\Layaway\CreateBackofficeLayawayAction',
            self::LayawayCancelled => 'App\Actions\Layaway\CancelLayawayAction',
            self::InstallmentRescheduled => 'App\Actions\Layaway\RescheduleInstallmentAction',
            self::LayawayDelivered => 'App\Actions\Layaway\MarkDeliveredAction',
            self::ReconciliationCorrected => 'App\Actions\Layaway\ReconcileLayawaysAction',

            self::ProductPricingChanged => 'App\Actions\Product\UpsertProductAction',
            self::ProductStatusChanged => 'App\Actions\Product\ChangeProductStatusAction',
            self::CatalogPublished, self::CatalogExpirationScheduled => 'App\Actions\Catalog\PublishCatalogAction',
            self::CatalogUnpublished => 'App\Actions\Catalog\UnpublishCatalogAction',

            self::CustomerMerged => 'App\Actions\Customer\MergeCustomersAction',
            self::CustomerIdentityConflict,
            self::CustomerContactUpdated => 'App\Actions\Customer\ResolveCustomerAction',
            self::AccountLinked => 'App\Actions\Account\LinkAccountAction',

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
            self::OrganizationClosed => 'App\Actions\Organization\CloseOrganizationAction',

            self::SubscriptionPlanChanged => 'App\Actions\Subscription\ChangePlanAction',
            self::SubscriptionInvoiceIssued => 'App\Actions\Subscription\IssueInvoiceAction',
            self::SubscriptionInvoicePaid => 'App\Actions\Subscription\ApproveSubscriptionReceiptAction',
            self::SubscriptionDowngradeApplied => 'App\Actions\Subscription\ApplyDowngradeAction',
            self::SubscriptionSuspended => 'App\Actions\Organization\SuspendOrganizationAction',
            self::SubscriptionReactivated => 'App\Actions\Organization\ReactivateOrganizationAction',

            self::SupportAccessGranted => 'App\Actions\Platform\GrantSupportAccessAction',
            self::SupportAccessRevoked => 'App\Actions\Platform\RevokeSupportAccessAction',
            self::PlatformSubscriptionOverridden => 'App\Actions\Platform\GrantCourtesyPeriodAction',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReceiptApproved => 'Comprobante aprobado',
            self::ReceiptRejected => 'Comprobante rechazado',
            self::PaymentRecorded => 'Pago registrado a mano',
            self::PaymentReallocated => 'Pago reasignado entre cuotas',
            self::LayawayCreated => 'Apartado creado desde el backoffice',
            self::LayawayCancelled => 'Apartado cancelado',
            self::InstallmentRescheduled => 'Cuota reprogramada',
            self::LayawayDelivered => 'Apartado marcado como entregado',
            self::ReconciliationCorrected => 'Saldo o existencias corregidos por la reconciliación',

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
            self::OrganizationClosed => 'Negocio cerrado',

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
