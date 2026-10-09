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
use LogicException;

/**
 * La ficha del cliente final, **una por organización** (`REQ-IDN-001`).
 *
 * El mismo documento en dos negocios son dos fichas que no saben la una de la
 * otra (RB-1, `Q1`): el global scope de `BelongsToOrganization` es lo que hace
 * cierto que al buscar por documento solo aparezca la de este negocio, y el
 * índice `UNIQUE(organization_id, document_number)` lo que impide que haya
 * dos aquí.
 *
 * Nace dentro de la transacción del apartado (RB-6) y solo la crea
 * `ResolveCustomerAction`: el backoffice no da de alta fichas a mano (RB-8),
 * y `CustomerCreationTest` lo afirma sobre `app/`. No tiene contraseña ni la
 * tendrá (RB-5).
 *
 * ## Es autenticable, sin contraseña
 *
 * Es lo que autentica el guard `customer`, la sesión **acotada** del portal
 * (`REQ-IDN-004`, `T-049`). Se abre con documento + código de apartado
 * (`StartScopedSessionAction`) y nunca con `attempt()`: no hay credencial que
 * comparar, y `getAuthPassword()` devuelve `null` porque la columna no existe.
 *
 * ## La evidencia de consentimiento no se puede perder
 *
 * `terms_accepted_at` y `terms_ip_address` son la constancia de que la persona
 * aceptó las condiciones (`REQ-IDN-005`), y son **evidencia legal**. Las
 * columnas admiten `NULL` en la base, pero una ficha **no puede nacer sin
 * ellas ni quedarse sin ellas después**: guardar un `Customer` nuevo sin las
 * dos, o borrarle una, lanza. Es la segunda capa; la primera es que
 * `ResolveCustomerAction::handle()` no se deja llamar sin `TermsConsent`.
 *
 * Los VO de `T-040` no se castean aquí: la columna guarda su forma canónica
 * (`DocumentNumber::$value`, `PhoneNumber::$value`), y quien necesite la
 * presentación la construye. `document_kind` sí, porque es un enum y leerlo
 * crudo obligaría a un `from()` en cada sitio.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $document_number forma canónica: `V12345678`, `J123456789`
 * @property DocumentKind $document_kind
 * @property string $full_name
 * @property string $phone E.164: `+584121234567`
 * @property string|null $email opcional y sin unicidad (RB-3)
 * @property int|null $account_id
 * @property \Illuminate\Support\Carbon|null $terms_accepted_at la última aceptación (`REQ-IDN-005` RB-3)
 * @property string|null $terms_ip_address
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property string|null $remember_token el «recuérdame» de la sesión acotada (`REQ-IDN-008` RB-4), no una credencial
 */
class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use BelongsToOrganization;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** `REQ-IDN-004` RB-4 · el token de sesión no viaja en ninguna respuesta. */
    protected $hidden = ['remember_token'];

    /** Las dos columnas de la evidencia de consentimiento. */
    public const CONSENT_COLUMNS = ['terms_accepted_at', 'terms_ip_address'];

    protected static function booted(): void
    {
        static::saving(static function (Customer $customer): void {
            foreach (self::CONSENT_COLUMNS as $column) {
                $missing = $customer->getAttribute($column) === null;

                if (! $customer->exists && $missing) {
                    throw new LogicException(sprintf(
                        'Una ficha de cliente no nace sin evidencia de consentimiento (REQ-IDN-005 RB-2) y falta «%s». '
                        .'Las fichas las crea ResolveCustomerAction, que exige un TermsConsent.',
                        $column,
                    ));
                }

                if ($customer->exists && $missing && $customer->isDirty($column)) {
                    throw new LogicException(sprintf(
                        'Se intentó borrar «%s» de una ficha de cliente. Es la evidencia de que aceptó las condiciones '
                        .'(REQ-IDN-005): se renueva con cada apartado, nunca se vacía.',
                        $column,
                    ));
                }
            }
        });
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
