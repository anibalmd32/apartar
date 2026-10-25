<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Account\AccountProvider;
use Database\Factories\AccountIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $account_id
 * @property AccountProvider $provider
 * @property string $provider_id
 * @property string $email
 */
class AccountIdentity extends Model
{
    /** @use HasFactory<AccountIdentityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'account_id',
        'provider',
        'provider_id',
        'email',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AccountProvider::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
