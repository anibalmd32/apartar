<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Subscription\InvoiceNoticeType;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $subscription_invoice_id
 * @property InvoiceNoticeType $type
 * @property \Illuminate\Support\Carbon $sent_at
 */
class SubscriptionInvoiceNotice extends Model
{
    use BelongsToOrganization;

    /**
     * @var list<string>
     */
    protected $fillable = ['subscription_invoice_id', 'type', 'sent_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InvoiceNoticeType::class,
            'sent_at' => 'datetime',
        ];
    }
}
