<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Domain\Platform\PlatformSettingKey;
use App\Models\PlatformUser;
use App\Support\Auditing\PlatformAudit;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdatePlatformSettingsAction
{
    /**
     * @param  array<string, int>  $values
     * @return list<PlatformSettingKey>
     *
     * @throws InvalidArgumentException
     */
    public function handle(array $values, PlatformUser $operator): array
    {
        $changes = [];

        foreach (PlatformSettingKey::cases() as $key) {
            if (! array_key_exists($key->value, $values)) {
                continue;
            }

            $value = $values[$key->value];

            if (! $key->accepts($value)) {
                [$min, $max] = $key->range();

                throw new InvalidArgumentException(sprintf('%s va de %d a %d %s.', $key->label(), $min, $max, $key->unit()));
            }

            $before = PlatformSettings::get($key);

            if ($before !== $value) {
                $changes[] = [$key, $before, $value];
            }
        }

        DB::transaction(function () use ($changes, $operator): void {
            foreach ($changes as [$key, $before, $after]) {
                PlatformSettings::set($key, $after, $operator);
                PlatformAudit::record(
                    PlatformAudit::SETTING_UPDATED,
                    sprintf('%s: de %d a %d %s.', $key->label(), $before, $after, $key->unit()),
                    $operator,
                    properties: ['key' => $key->value, 'before' => $before, 'after' => $after],
                );
            }
        });

        return array_map(fn (array $change): PlatformSettingKey => $change[0], $changes);
    }
}
