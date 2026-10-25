<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
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
