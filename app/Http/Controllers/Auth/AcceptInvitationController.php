<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\TeamMemberships;
use App\Domain\Tenancy\TeamInvitation;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\Auth\AcceptInvitationRequest;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AcceptInvitationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TeamMemberships $memberships,
    ) {}

    public function show(string $token): Response
    {
        $organization = $this->organizationOf($token);
        $member = $this->pendingMemberFor($token, $organization);

        if (! $member instanceof User) {
            return $this->expired($organization);
        }

        if ($this->hasExpired($member)) {
            $this->context->runFor(
                $organization,
                fn () => $member->forceFill(['invitation_token_hash' => null])->save(),
            );

            return $this->expired($organization);
        }

        return Inertia::render('backoffice/auth/AcceptInvitation', [
            'token' => $token,
            'hasAccount' => $organization instanceof Organization && $this->memberships->accepted($member->email, (int) $organization->getKey()) instanceof User,
            'name' => $member->name,
            'email' => $member->email,
            'organizationName' => $organization?->name,
            'role' => $member->role->value,
            'expiresInHours' => (int) ceil(max(0, CarbonImmutable::now()->diffInMinutes($member->invitation_expires_at)) / 60),
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $organization = $this->organizationOf($token);
        $member = $this->pendingMemberFor($token, $organization);

        if (! $member instanceof User || $this->hasExpired($member) || ! $organization instanceof Organization) {
            return to_route('team.invitation.show', ['token' => $token]);
        }

        $existing = $this->memberships->accepted($member->email, (int) $organization->getKey());

        if ($existing instanceof User) {
            if (! Hash::check((string) $request->string('password'), (string) $existing->getAuthPassword())) {
                throw ValidationException::withMessages(['password' => 'Esa no es tu contraseña de Apartar.']);
            }
        } else {
            $request->validate(['password' => ['confirmed', PasswordRule::min(12)->mixedCase()->numbers()->uncompromised()]]);
        }

        $password = $existing instanceof User ? (string) $existing->getAuthPassword() : (string) $request->string('password');

        $this->context->runFor($organization, function () use ($member, $password): void {
            DB::transaction(function () use ($member, $password): void {
                $member->forceFill([
                    'password' => $password,
                    'invitation_token_hash' => null,
                    'invitation_expires_at' => null,
                    'must_change_password' => false,
                    'email_verified_at' => $member->email_verified_at ?? now(),
                ])->save();
            });
        });

        Auth::guard('web')->login($member);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function organizationOf(string $token): ?Organization
    {
        $organizationId = TeamInvitation::organizationIdIn($token);

        return $organizationId === null
            ? null
            : Organization::query()->find($organizationId);
    }

    private function pendingMemberFor(string $token, ?Organization $organization): ?User
    {
        if (! $organization instanceof Organization) {
            return null;
        }

        return $this->context->runFor($organization, fn (): ?User => User::query()
            ->where('invitation_token_hash', TeamInvitation::hash($token))
            ->whereNull('password')
            ->whereNull('disabled_at')
            ->first());
    }

    private function hasExpired(User $member): bool
    {
        return $member->invitation_expires_at === null
            || $member->invitation_expires_at->isPast();
    }

    private function expired(?Organization $organization): Response
    {
        return Inertia::render('backoffice/auth/InvitationExpired', [
            'organizationName' => $organization?->name,
        ]);
    }
}
