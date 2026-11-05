<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use Illuminate\Database\Eloquent\Model;

final class AuditSubjectName
{
    /**
     * @var array<string, string>
     */
    private const NOUNS = [
        'Account' => 'cuenta de cliente',
        'Catalog' => 'catálogo',
        'Category' => 'categoría',
        'Customer' => 'cliente',
        'CustomerMerge' => 'unión de fichas',
        'ExportJob' => 'exportación',
        'Installment' => 'cuota',
        'Layaway' => 'apartado',
        'ManualPush' => 'aviso',
        'Organization' => 'negocio',
        'OrganizationSetting' => 'ajuste',
        'Payment' => 'pago',
        'PaymentAccount' => 'cuenta de cobro',
        'PaymentAllocation' => 'reparto de un pago',
        'Plan' => 'plan',
        'Product' => 'producto',
        'Receipt' => 'comprobante',
        'Subscription' => 'suscripción',
        'SubscriptionInvoice' => 'factura',
        'SubscriptionReceipt' => 'pago de factura',
        'SupportAccessGrant' => 'acceso de soporte',
        'Tag' => 'etiqueta',
        'User' => 'miembro del equipo',
    ];

    public static function of(Model $subject, int|string $key): string
    {
        return self::label(class_basename($subject), (string) $key);
    }

    public static function humanize(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        return (string) preg_replace_callback(
            '/\b([A-Z][A-Za-z]+) #(\d+)\b/',
            fn (array $match): string => isset(self::NOUNS[$match[1]]) ? self::label($match[1], $match[2]) : $match[0],
            $description,
        );
    }

    private static function label(string $basename, string $key): string
    {
        return sprintf('%s n.º %s', self::NOUNS[$basename] ?? $basename, $key);
    }
}
