<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\PlatformUser;
use App\Support\Auditing\PlatformAudit;
use InvalidArgumentException;

final class ResetPlatformOperatorPasswordAction
{
    /**
     * @throws InvalidArgumentException
     */
    public function handle(PlatformUser $operator, PlatformUser $actor): string
    {
        if ($operator->is($actor)) {
            throw new InvalidArgumentException('Tu propia contraseña se cambia desde tu menú.');
        }

        $password = CreatePlatformOperatorAction::temporaryPassword();
        $operator->forceFill(['password' => $password, 'must_change_password' => true])->save();

        PlatformAudit::record(PlatformAudit::OPERATOR_PASSWORD_RESET, sprintf('Restableció la contraseña de %s.', $operator->name), $actor, properties: [
            'operator_id' => $operator->getKey(),
        ]);

        return $password;
    }
}
