<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\UpdatePlatformSettingsAction;
use App\Domain\Platform\PlatformSettingKey;
use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $request->user('platform');

        return Inertia::render('platform/settings/Index', [
            'settings' => array_map(fn (PlatformSettingKey $key): array => [
                'key' => $key->value,
                'label' => $key->label(),
                'unit' => $key->unit(),
                'value' => PlatformSettings::get($key),
                'default' => $key->default(),
                'min' => $key->range()[0],
                'max' => $key->range()[1],
            ], PlatformSettingKey::cases()),
            'operators' => PlatformUser::query()->orderByRaw('disabled_at is not null')->orderBy('name')->get()->map(fn (PlatformUser $operator): array => [
                'id' => $operator->getKey(),
                'name' => $operator->name,
                'email' => $operator->email,
                'active' => $operator->isActive(),
                'mustChangePassword' => $operator->must_change_password,
                'isMe' => $me instanceof PlatformUser && $operator->is($me),
            ])->all(),
            'temporaryPassword' => $request->session()->get('temporaryPassword'),
        ]);
    }

    public function update(Request $request, UpdatePlatformSettingsAction $update): RedirectResponse
    {
        $rules = [];

        foreach (PlatformSettingKey::cases() as $key) {
            [$min, $max] = $key->range();
            $rules['settings.'.$key->value] = ['required', 'integer', 'min:'.$min, 'max:'.$max];
        }

        $data = $request->validate($rules, attributes: collect(PlatformSettingKey::cases())
            ->mapWithKeys(fn (PlatformSettingKey $key): array => ['settings.'.$key->value => mb_strtolower($key->label())])
            ->all());

        $operator = $request->user('platform');
        abort_unless($operator instanceof PlatformUser, 401);

        try {
            $changed = $update->handle(array_map('intval', $data['settings']), $operator);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['settings' => $exception->getMessage()]);
        }

        return back()->with('status', $changed === [] ? 'No había cambios que guardar.' : 'Ajustes guardados.');
    }
}
