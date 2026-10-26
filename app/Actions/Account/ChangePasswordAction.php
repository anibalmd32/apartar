<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Auth\TeamMemberships;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ChangePasswordAction
{
    public function __construct(private readonly TeamMemberships $memberships) {}

    public function handle(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password' => $password, 'must_change_password' => false])->save();

            $this->memberships->syncPassword($user);
        });
    }
}
