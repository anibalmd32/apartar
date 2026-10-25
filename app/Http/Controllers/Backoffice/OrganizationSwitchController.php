<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Auth\TeamMemberships;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, Organization $organization, TeamMemberships $memberships): RedirectResponse
    {
        /** @var User $current */
        $current = $request->user();
        $target = $memberships->in($current, (int) $organization->getKey()) ?? abort(404);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $target->forceFill(['last_login_at' => CarbonImmutable::now()])->saveQuietly();

        return to_route('dashboard')->with('status', sprintf('Estás en %s.', $organization->name));
    }
}
