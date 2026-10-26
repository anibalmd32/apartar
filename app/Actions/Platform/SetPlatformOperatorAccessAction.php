<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\PlatformUser;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SetPlatformOperatorAccessAction
{
    /**
     * @throws InvalidArgumentException
     */
    public function handle(PlatformUser $operator, bool $active, PlatformUser $actor): PlatformUser
    {
        if ($operator->isActive() === $active) {
            return $operator;
        }

        if (! $active && $operator->is($actor)) {
            throw new InvalidArgumentException('No puedes desactivar tu propia cuenta.');
        }

        return DB::transaction(function () use ($operator, $active, $actor): PlatformUser {
            if (! $active && PlatformUser::query()->whereNull('disabled_at')->lockForUpdate()->count() <= 1) {
                throw new InvalidArgumentException('Tiene que quedar al menos un operador activo.');
            }

            $operator->forceFill(['disabled_at' => $active ? null : CarbonImmutable::now()])->save();

            PlatformAudit::record(
                $active ? PlatformAudit::OPERATOR_ENABLED : PlatformAudit::OPERATOR_DISABLED,
                sprintf('%s al operador %s.', $active ? 'Reactivó' : 'Desactivó', $operator->name),
                $actor,
                properties: ['operator_id' => $operator->getKey()],
            );

            return $operator;
        });
    }
}
