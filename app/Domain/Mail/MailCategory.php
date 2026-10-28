<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final class MailCategory
{
    public const MANUAL = 'OwnerMail';

    public const UNKNOWN = 'Otro';

    public const MAX_LENGTH = 100;

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        self::MANUAL => 'Escrito por el equipo',
        'CustomerLoginLinkNotification' => 'Enlace de acceso del cliente',
        'CustomerMessageNotification' => 'Aviso al cliente',
        'ExportFinishedNotification' => 'Exportación lista',
        'InvoiceOverdueNotification' => 'Factura vencida',
        'LayawayCodeNotification' => 'Código del apartado',
        'LayawayConfirmationNotification' => 'Confirmación del apartado',
        'SubscriptionReceiptReviewedNotification' => 'Comprobante revisado',
        'SubscriptionSuspendedNotification' => 'Suscripción suspendida',
        'SupportAccessGrantedNotification' => 'Acceso de soporte',
        'TeamInvitationNotification' => 'Invitación al equipo',
        'ResetPassword' => 'Restablecer contraseña',
        'VerifyEmail' => 'Confirmar correo',
    ];

    /**
     * @param  array<mixed>  $data
     */
    public static function fromMessageData(array $data): string
    {
        foreach (['__laravel_notification', '__laravel_mailable'] as $key) {
            $class = $data[$key] ?? null;

            if (is_string($class) && $class !== '') {
                return self::fromClass($class);
            }
        }

        return self::UNKNOWN;
    }

    public static function fromClass(string $class): string
    {
        $short = substr((string) strrchr('\\'.$class, '\\'), 1);

        return mb_substr($short === '' ? self::UNKNOWN : $short, 0, self::MAX_LENGTH);
    }

    public static function label(string $category): string
    {
        return self::LABELS[$category] ?? $category;
    }
}
