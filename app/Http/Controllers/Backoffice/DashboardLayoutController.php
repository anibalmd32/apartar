<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\UpdateDashboardLayoutRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class DashboardLayoutController extends Controller
{
    public function __invoke(UpdateDashboardLayoutRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['dashboard_layout' => $request->toLayout()->toArray()])->save();

        return back();
    }
}
