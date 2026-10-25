<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

final class PlatformAdminSeeder extends Seeder
{
    public const VARIABLES = ['PLATFORM_ADMIN_NAME', 'PLATFORM_ADMIN_EMAIL', 'PLATFORM_ADMIN_PASSWORD'];

    private const CONFIG_KEYS = ['PLATFORM_ADMIN_NAME' => 'name', 'PLATFORM_ADMIN_EMAIL' => 'email', 'PLATFORM_ADMIN_PASSWORD' => 'password'];

    /**
     * @param  array<string, string|null>|null  $environment
     *
     * @throws RuntimeException
     */
    public function run(?array $environment = null): void
    {
        $values = [];

        foreach (self::VARIABLES as $variable) {
            $value = $environment === null ? config('platform.admin.'.self::CONFIG_KEYS[$variable]) : ($environment[$variable] ?? null);
            $values[$variable] = is_string($value) ? trim($value) : '';
        }

        $missing = array_keys(array_filter($values, fn (string $value): bool => $value === ''));

        if ($missing !== []) {
            throw new RuntimeException('Faltan variables para el operador de plataforma: '.implode(', ', $missing).'.');
        }

        $email = mb_strtolower($values['PLATFORM_ADMIN_EMAIL']);

        $validator = Validator::make(
            ['email' => $email, 'password' => $values['PLATFORM_ADMIN_PASSWORD']],
            ['email' => ['email'], 'password' => [Password::min(12)->mixedCase()->numbers()->uncompromised()]],
        );

        if ($validator->fails()) {
            throw new RuntimeException('El operador de plataforma no se crea: '.implode(' ', $validator->errors()->all()));
        }

        if (PlatformUser::query()->where('email', $email)->exists()) {
            return;
        }

        $user = new PlatformUser;
        $user->forceFill([
            'name' => $values['PLATFORM_ADMIN_NAME'],
            'email' => $email,
            'password' => $values['PLATFORM_ADMIN_PASSWORD'],
            'must_change_password' => true,
        ])->save();
    }
}
