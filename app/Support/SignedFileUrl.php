<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\ResolvePortalTenant;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\URL;

final class SignedFileUrl
{
    public const CUSTOMER_TTL_MINUTES = 5;

    public const BACKOFFICE_TTL_MINUTES = 15;

    public const CUSTOMER_ROUTE = 'portal.receipts.file';

    public const BACKOFFICE_ROUTE = 'receipts.file';

    public const CUSTOMER_PAYMENT_ROUTE = 'portal.payments.file';

    public const BACKOFFICE_PAYMENT_ROUTE = 'payments.file';

    public static function forCustomer(Receipt $receipt): string
    {
        return URL::temporarySignedRoute(self::CUSTOMER_ROUTE, now()->addMinutes(self::CUSTOMER_TTL_MINUTES), [
            'receipt' => $receipt->getKey(),
            ResolvePortalTenant::BUSINESS_PARAMETER => $receipt->organization_id,
        ]);
    }

    public static function forBackoffice(Receipt $receipt): string
    {
        return self::sign(self::BACKOFFICE_ROUTE, $receipt, self::BACKOFFICE_TTL_MINUTES);
    }

    public static function forCustomerPayment(Payment $payment): string
    {
        return URL::temporarySignedRoute(self::CUSTOMER_PAYMENT_ROUTE, now()->addMinutes(self::CUSTOMER_TTL_MINUTES), [
            'payment' => $payment->getKey(),
            ResolvePortalTenant::BUSINESS_PARAMETER => $payment->organization_id,
        ]);
    }

    public static function forBackofficePayment(Payment $payment): string
    {
        return URL::temporarySignedRoute(self::BACKOFFICE_PAYMENT_ROUTE, now()->addMinutes(self::BACKOFFICE_TTL_MINUTES), [
            'payment' => $payment->getKey(),
        ]);
    }

    private static function sign(string $route, Receipt $receipt, int $minutes): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes($minutes), ['receipt' => $receipt->getKey()]);
    }
}
