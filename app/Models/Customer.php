<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Support\DocumentKind;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use LogicException;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $document_number
 * @property DocumentKind $document_kind
 * @property string $full_name
 * @property string $phone
 * @property string|null $email
 * @property int|null $account_id
 * @property \Illuminate\Support\Carbon|null $terms_accepted_at
 * @property string|null $terms_ip_address
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $remember_token
 */
class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use BelongsToOrganization;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use HasPushSubscriptions, Notifiable;

    protected $hidden = ['remember_token'];

    public const CONSENT_COLUMNS = ['terms_accepted_at', 'terms_ip_address'];

    protected static function booted(): void
    {
        static::saving(static function (Customer $customer): void {
            foreach (self::CONSENT_COLUMNS as $column) {
                $missing = $customer->getAttribute($column) === null;

                if (! $customer->exists && $missing) {
                    throw new LogicException(sprintf(
                        'Una ficha de cliente no nace sin evidencia de consentimiento y falta «%s». '
                        .'Las fichas las crea ResolveCustomerAction, que exige un TermsConsent.',
                        $column,
                    ));
                }

                if ($customer->exists && $missing && $customer->isDirty($column)) {
                    throw new LogicException(sprintf(
                        'Se intentó borrar «%s» de una ficha de cliente. Es la evidencia de que aceptó las condiciones '
                        .': se renueva con cada apartado, nunca se vacía.',
                        $column,
                    ));
                }
            }
        });
    }

    /**
     * @return HasOne<Conversation, $this>
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'document_kind' => DocumentKind::class,
            'terms_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
}
