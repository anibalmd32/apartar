<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\ResolvePortalTenant;
use App\Models\Receipt;
use Illuminate\Support\Facades\URL;

final class SignedFileUrl
{
    public const CUSTOMER_TTL_MINUTES = 5;

    public const BACKOFFICE_TTL_MINUTES = 15;

    public const CUSTOMER_ROUTE = 'portal.receipts.file';

    public const BACKOFFICE_ROUTE = 'receipts.file';

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

    private static function sign(string $route, Receipt $receipt, int $minutes): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes($minutes), ['receipt' => $receipt->getKey()]);
    }
}
