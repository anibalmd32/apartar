<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CollectionGroupingController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['grouped' => ['required', 'boolean']]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['collections_grouped' => $request->boolean('grouped')])->save();

        return back();
    }
}
