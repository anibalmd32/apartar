<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\OrganizationSettingFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property SettingKey $key
 * @property mixed $value
 * @property int|null $updated_by
 */
class OrganizationSetting extends Model
{
    /** @use HasFactory<OrganizationSettingFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key' => SettingKey::class,
        ];
    }

    /**
     * @return Attribute<mixed, string>
     */
    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): mixed => $value === null ? null : json_decode($value, true),
            set: fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
