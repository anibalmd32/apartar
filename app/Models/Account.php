<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $display_name
 * @property string $email
 * @property string|null $avatar_url
 * @property string $locale
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class Account extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use HasPushSubscriptions, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'display_name',
        'email',
        'avatar_url',
        'locale',
        'last_login_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AccountIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(AccountIdentity::class);
    }

    /**
     * @return HasMany<CatalogSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CatalogSubscription::class);
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
