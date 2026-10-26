<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Auth\TeamMemberships;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateProfileAction
{
    public function __construct(private readonly TeamMemberships $memberships) {}

    public function handle(User $user, string $name, string $email): User
    {
        $previousEmail = $user->email;
        $email = mb_strtolower(trim($email));
        $changedEmail = $email !== $previousEmail;

        DB::transaction(function () use ($user, $name, $email, $changedEmail, $previousEmail): void {
            $user->forceFill(['name' => trim($name), 'email' => $email]);

            if ($changedEmail) {
                $user->forceFill(['email_verified_at' => null]);
            }

            $user->save();

            $this->memberships->syncProfile($user, $previousEmail);
        });

        if ($changedEmail) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }
}
