<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Platform\PlatformSettingKey;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Support\PlatformSettings;
use Database\Factories\ExportJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $requested_by
 * @property ExportType $type
 * @property ExportStatus $status
 * @property array<string, mixed> $filters
 * @property string|null $file_path
 * @property int|null $file_size
 * @property int|null $row_count
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $requester
 */
class ExportJob extends Model
{
    /** @use HasFactory<ExportJobFactory> */
    use BelongsToOrganization, HasFactory;

    public const DISK = 'exports';

    public const RETENTION_DAYS = 7;

    public const STALE_MINUTES = 15;

    public const LINK_TTL_HOURS = 24;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'filters' => 'array',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function downloadUrl(): string
    {
        return URL::temporarySignedRoute('exports.download', now()->addHours(PlatformSettings::get(PlatformSettingKey::ExportLinkTtlHours)), ['export' => $this->getKey()]);
    }

    public function downloadName(): string
    {
        return sprintf('%s-%s.xlsx', $this->type->filename(), $this->created_at->toDateString());
    }
}
