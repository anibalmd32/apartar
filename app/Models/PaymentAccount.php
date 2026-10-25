<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\PaymentAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property PaymentMethod $method
 * @property string|null $bank
 * @property string $holder_name
 * @property string $holder_document
 * @property string $identifier
 * @property string|null $instructions
 * @property bool $is_active
 * @property int $position
 */
class PaymentAccount extends Model
{
    /** @use HasFactory<PaymentAccountFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'method',
        'bank',
        'holder_name',
        'holder_document',
        'identifier',
        'instructions',
        'is_active',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }
}
