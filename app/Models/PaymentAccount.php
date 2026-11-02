<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Payment\PaymentMethod;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Models\Concerns\ReferencesBank;
use App\Models\Concerns\ReferencesMobilePrefix;
use App\Support\PhoneNumber;
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
    use BelongsToOrganization, HasFactory, ReferencesBank, ReferencesMobilePrefix;

    public function phoneColumn(): string
    {
        return 'identifier';
    }

    public function carriesMobilePhone(): bool
    {
        return $this->method === PaymentMethod::PagoMovil;
    }

    public function readableIdentifier(): string
    {
        if (! $this->carriesMobilePhone()) {
            return $this->identifier;
        }

        return PhoneNumber::tryFromInput($this->identifier)?->formatted() ?? $this->identifier;
    }

    public function describe(): string
    {
        return implode(' · ', array_filter([$this->method->label(), $this->bank, $this->readableIdentifier()]));
    }

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
