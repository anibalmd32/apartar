<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Customer;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use LogicException;

final class PortalLogin
{
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    public function open(Customer $customer, bool $remember = true): void
    {
        $guard = Auth::guard('customer');

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('El guard «customer» tiene que ser de sesión (config/auth.php).');
        }

        $guard->setRememberDuration(self::REMEMBER_MINUTES);
        $guard->login($customer, remember: $remember);
    }
}
