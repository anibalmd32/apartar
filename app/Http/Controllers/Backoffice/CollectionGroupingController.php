<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CollectionGroupingController extends Controller
{
    public const DETAILS = ['layaways', 'installments'];

    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'grouped' => ['sometimes', 'boolean'],
            'detail' => ['sometimes', 'string', Rule::in(self::DETAILS)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(array_filter([
            'collections_grouped' => $request->has('grouped') ? $request->boolean('grouped') : null,
            'collections_detail' => $request->has('detail') ? $request->string('detail')->toString() : null,
        ], fn (mixed $value): bool => $value !== null))->save();

        return back();
    }
}
