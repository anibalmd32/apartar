<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Chat\MessageSenderType;
use App\Domain\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $customer_id
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property int $customer_unread_count
 * @property int $admin_unread_count
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use BelongsToOrganization, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'customer_id',
        'last_message_at',
        'customer_unread_count',
        'admin_unread_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'customer_unread_count' => 'integer',
            'admin_unread_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function markReadBy(MessageSenderType $reader): int
    {
        $written = $reader === MessageSenderType::Customer ? MessageSenderType::Team : MessageSenderType::Customer;
        $counter = $reader === MessageSenderType::Customer ? 'customer_unread_count' : 'admin_unread_count';

        return DB::transaction(function () use ($written, $counter): int {
            $marked = $this->messages()
                ->where('sender_type', $written)
                ->whereNull('read_at')
                ->update(['read_at' => CarbonImmutable::now()]);

            if ($marked > 0) {
                static::query()->whereKey($this->getKey())->update([
                    $counter => DB::raw(sprintf('GREATEST(CAST(%1$s AS SIGNED) - %2$d, 0)', $counter, $marked)),
                ]);
            }

            $this->refresh();

            return $marked;
        });
    }
}
