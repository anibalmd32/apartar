<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Mail\MailEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $event_id
 * @property int|null $sent_mail_id
 * @property string $mailtrap_message_id
 * @property MailEventType $type
 * @property string $email
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string|null $url
 * @property string|null $reason
 * @property int|null $response_code
 * @property string|null $bounce_category
 * @property string|null $ip
 * @property string|null $user_agent
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $created_at
 */
class SentMailEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<SentMail, $this>
     */
    public function sentMail(): BelongsTo
    {
        return $this->belongsTo(SentMail::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MailEventType::class,
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
