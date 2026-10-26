<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\PlatformUser;
use App\Support\Auditing\PlatformAudit;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreatePlatformOperatorAction
{
    public const TEMPORARY_PASSWORD_LENGTH = 16;

    /**
     * @return array{PlatformUser, string}
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $name, string $email, PlatformUser $actor): array
    {
        $email = mb_strtolower(trim($email));

        if (PlatformUser::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('Ya hay un operador con ese correo.');
        }

        $password = self::temporaryPassword();
        $operator = new PlatformUser;
        $operator->forceFill([
            'name' => trim($name),
            'email' => $email,
            'password' => $password,
            'must_change_password' => true,
        ])->save();

        PlatformAudit::record(PlatformAudit::OPERATOR_CREATED, sprintf('Creó el operador %s (%s).', $operator->name, $email), $actor, properties: [
            'operator_id' => $operator->getKey(),
        ]);

        return [$operator, $password];
    }

    public static function temporaryPassword(): string
    {
        return Str::password(self::TEMPORARY_PASSWORD_LENGTH, symbols: false);
    }
}
