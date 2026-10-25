<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Chat\MessageSenderType;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $conversation_id
 * @property MessageSenderType $sender_type
 * @property int $sender_id
 * @property string $body
 * @property string|null $idempotency_key
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToOrganization, HasFactory;

    public const MAX_LENGTH = 2000;

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'body',
        'idempotency_key',
        'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_type' => MessageSenderType::class,
            'sender_id' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
