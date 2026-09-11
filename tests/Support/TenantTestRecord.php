<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de prueba del aislamiento.
 *
 * Existe solo en la suite y no en `app/Models` a propósito: en `T-005` todavía
 * no hay ningún modelo del tenant —`User` y `PaymentAccount` llegan con
 * `T-008`—, y el trait tiene que poder probarse sin esperar a ellos ni
 * aplicarse antes de tiempo a un modelo real.
 *
 * Su tabla la crea `TenantTestCase` antes de abrir la transacción del test.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 */
final class TenantTestRecord extends Model
{
    use BelongsToOrganization;

    protected $table = 'tenant_test_records';

    /** @var list<string> */
    protected $fillable = ['name'];
}
