<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $sent_by
 * @property string $title
 * @property string $body
 * @property string $mode
 * @property string|null $segment
 * @property int $recipients
 * @property int $queued
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read User|null $sender
 */
class ManualPush extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['sent_by', 'title', 'body', 'mode', 'segment', 'recipients', 'queued'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'integer',
            'queued' => 'integer',
        ];
    }
}
