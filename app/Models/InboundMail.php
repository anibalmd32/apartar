<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $mailtrap_message_id
 * @property int $mailtrap_inbox_id
 * @property string|null $mailtrap_thread_id
 * @property int $mail_thread_id
 * @property string $from_address
 * @property string|null $from_name
 * @property list<string> $to
 * @property list<string> $cc
 * @property string|null $reply_to
 * @property string $subject
 * @property string|null $message_id
 * @property string|null $in_reply_to
 * @property list<string> $references
 * @property array<string, string> $headers
 * @property string|null $html_body
 * @property string|null $text_body
 * @property int|null $size
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $read_at
 */
class InboundMail extends Model
{
    /** @use HasFactory<\Database\Factories\InboundMailFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<MailThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'mail_thread_id');
    }

    /**
     * @return HasMany<InboundMailAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(InboundMailAttachment::class);
    }

    public function replyAddress(): string
    {
        return $this->reply_to ?? $this->from_address;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'references' => 'array',
            'headers' => 'array',
            'payload' => 'array',
            'received_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
