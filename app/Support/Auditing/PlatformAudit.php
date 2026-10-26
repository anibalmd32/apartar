<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use App\Models\PlatformActivity;
use App\Models\PlatformUser;
use Throwable;

final class PlatformAudit
{
    public const SUPPORT_GRANTED = 'support_access.granted';

    public const SUPPORT_REVOKED = 'support_access.revoked';

    public const SUPPORT_VIEWED = 'support_access.viewed';

    public const INVOICE_APPROVED = 'invoice.receipt_approved';

    public const INVOICE_REJECTED = 'invoice.receipt_rejected';

    public const INVOICE_VOIDED = 'invoice.voided';

    public const PLAN_OVERRIDDEN = 'subscription.plan_overridden';

    public const COURTESY_GRANTED = 'subscription.courtesy_granted';

    public const ORGANIZATION_SUSPENDED = 'organization.suspended';

    public const ORGANIZATION_REACTIVATED = 'organization.reactivated';

    public const ORGANIZATION_CLOSED = 'organization.closed';

    public const LOGIN = 'operator.login';

    public const COURTESY_REVOKED = 'subscription.courtesy_revoked';

    public const INVOICE_ISSUED = 'invoice.issued_manually';

    public const SETTING_UPDATED = 'platform.setting_updated';

    public const OPERATOR_CREATED = 'operator.created';

    public const OPERATOR_DISABLED = 'operator.disabled';

    public const OPERATOR_ENABLED = 'operator.enabled';

    public const OPERATOR_PASSWORD_RESET = 'operator.password_reset';

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::SUPPORT_GRANTED => 'Abrió un acceso de soporte',
        self::SUPPORT_REVOKED => 'Cerró un acceso de soporte',
        self::SUPPORT_VIEWED => 'Consultó datos con acceso de soporte',
        self::INVOICE_APPROVED => 'Aprobó un comprobante',
        self::INVOICE_REJECTED => 'Rechazó un comprobante',
        self::INVOICE_VOIDED => 'Anuló una factura',
        self::INVOICE_ISSUED => 'Emitió una factura',
        self::PLAN_OVERRIDDEN => 'Cambió el plan',
        self::COURTESY_GRANTED => 'Concedió una cortesía',
        self::COURTESY_REVOKED => 'Revocó una cortesía',
        self::ORGANIZATION_SUSPENDED => 'Suspendió una organización',
        self::ORGANIZATION_REACTIVATED => 'Reactivó una organización',
        self::ORGANIZATION_CLOSED => 'Cerró una organización',
        self::SETTING_UPDATED => 'Cambió un ajuste',
        self::OPERATOR_CREATED => 'Creó un operador',
        self::OPERATOR_DISABLED => 'Desactivó un operador',
        self::OPERATOR_ENABLED => 'Reactivó un operador',
        self::OPERATOR_PASSWORD_RESET => 'Restableció una contraseña',
        self::LOGIN => 'Inició sesión',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(string $action, string $description, ?PlatformUser $actor = null, ?int $organizationId = null, array $properties = []): void
    {
        try {
            $entry = new PlatformActivity;
            $entry->forceFill([
                'platform_user_id' => $actor?->getKey(),
                'target_organization_id' => $organizationId,
                'action' => $action,
                'description' => mb_substr($description, 0, 500),
                'properties' => $properties,
                'created_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
