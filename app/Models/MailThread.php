<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $mailtrap_thread_id
 * @property string $subject
 * @property string $counterpart
 * @property string $snippet
 * @property int $messages_count
 * @property int $unread_count
 * @property \Illuminate\Support\Carbon $last_message_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class MailThread extends Model
{
    /** @use HasFactory<\Database\Factories\MailThreadFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return HasMany<InboundMail, $this>
     */
    public function inboundMails(): HasMany
    {
        return $this->hasMany(InboundMail::class);
    }

    /**
     * @return HasMany<SentMail, $this>
     */
    public function sentMails(): HasMany
    {
        return $this->hasMany(SentMail::class);
    }

    public function refreshCounters(): void
    {
        $inbound = $this->inboundMails()->count();
        $sent = $this->sentMails()->count();
        $latestInbound = $this->inboundMails()->max('received_at');
        $latestSent = $this->sentMails()->max('created_at');

        $this->forceFill([
            'messages_count' => $inbound + $sent,
            'unread_count' => $this->inboundMails()->whereNull('read_at')->count(),
            'last_message_at' => max(array_filter([(string) $latestInbound, (string) $latestSent, (string) $this->last_message_at])),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }
}
