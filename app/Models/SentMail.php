<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Mail\MailStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $message_id
 * @property string|null $mailtrap_message_id
 * @property string $from_address
 * @property string|null $from_name
 * @property list<string> $to
 * @property list<string> $cc
 * @property list<string> $bcc
 * @property string $recipient_domain
 * @property string $subject
 * @property string|null $html_body
 * @property string|null $text_body
 * @property array<string, string> $headers
 * @property string $category
 * @property MailStatus $status
 * @property int|null $target_organization_id
 * @property int|null $platform_user_id
 * @property int|null $mail_thread_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $first_opened_at
 * @property \Illuminate\Support\Carbon|null $first_clicked_at
 * @property int $opens_count
 * @property int $clicks_count
 * @property \Illuminate\Support\Carbon|null $last_event_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class SentMail extends Model
{
    /** @use HasFactory<\Database\Factories\SentMailFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    public function effectiveStatus(?CarbonImmutable $now = null): MailStatus
    {
        return $this->status->effective($this->created_at, $now ?? CarbonImmutable::now());
    }

    /**
     * @param  Builder<SentMail>  $query
     */
    public static function whereEffectiveStatus(Builder $query, MailStatus $status): void
    {
        $cutoff = CarbonImmutable::now()->subMinutes(MailStatus::PENDING_TIMEOUT_MINUTES);

        match ($status) {
            MailStatus::Failed => $query->where(fn (Builder $inner) => $inner
                ->where('status', MailStatus::Failed->value)
                ->orWhere(fn (Builder $pending) => $pending->where('status', MailStatus::Sending->value)->where('created_at', '<', $cutoff))),
            MailStatus::Sending => $query->where('status', MailStatus::Sending->value)->where('created_at', '>=', $cutoff),
            default => $query->where('status', $status->value),
        };
    }

    /**
     * @return HasMany<SentMailEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SentMailEvent::class);
    }

    /**
     * @return BelongsTo<MailThread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(MailThread::class, 'mail_thread_id');
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'headers' => 'array',
            'status' => MailStatus::class,
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'first_clicked_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }
}
