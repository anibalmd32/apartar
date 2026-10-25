<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Account;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use LogicException;

final class AccountSession
{
    public const GUARD = 'account';

    public function open(Account $account): void
    {
        $this->guard()->login($account);
    }

    public function close(): void
    {
        $this->guard()->logout();
    }

    private function guard(): SessionGuard
    {
        $guard = Auth::guard(self::GUARD);

        if (! $guard instanceof SessionGuard) {
            throw new LogicException('El guard «account» tiene que ser de sesión (config/auth.php).');
        }

        return $guard;
    }
}
