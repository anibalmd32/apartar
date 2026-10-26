<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Account\ChangePasswordAction;
use App\Actions\Account\UpdateProfileAction;
use App\Auth\TeamMemberships;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ChangePasswordRequest;
use App\Http\Requests\Backoffice\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController extends Controller
{
    public function edit(Request $request, TeamMemberships $memberships): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('backoffice/profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'emailVerified' => $user->email_verified_at !== null,
                'businesses' => $memberships->of($user)->count(),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $update): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $previousEmail = $user->email;

        $update->handle($user, (string) $request->string('name'), (string) $request->string('email'));

        return back()->with(
            'status',
            $user->email === $previousEmail ? 'Datos guardados.' : 'Datos guardados. Te enviamos un enlace a tu correo nuevo para confirmarlo.',
        );
    }

    public function updatePassword(ChangePasswordRequest $request, ChangePasswordAction $change): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $change->handle($user, (string) $request->string('password'));

        $request->session()->regenerate();

        return back()->with('status', 'Contraseña cambiada.');
    }
}
