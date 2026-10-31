<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Payment\RejectionReasonCode;
use App\Domain\Tenancy\BelongsToOrganization;
use App\Models\Concerns\ReferencesBank;
use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $layaway_id
 * @property int $customer_id
 * @property int|null $payment_account_id
 * @property PaymentMethod $payment_method
 * @property string|null $bank
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon $paid_at
 * @property string $declared_amount
 * @property Currency $declared_currency
 * @property string|null $note
 * @property string $file_path
 * @property string $file_hash
 * @property string $file_mime
 * @property int $file_size
 * @property ReceiptStatus $status
 * @property int|null $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property RejectionReasonCode|null $rejection_reason_code
 * @property string|null $rejection_reason
 * @property int|null $replaces_receipt_id
 */
class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use BelongsToOrganization, HasFactory, ReferencesBank;

    public const DISK = 'receipts';

    /**
     * @return BelongsTo<Layaway, $this>
     */
    public function layaway(): BelongsTo
    {
        return $this->belongsTo(Layaway::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PaymentAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_receipt_id');
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_receipt_id');
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePendingReview(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ReceiptStatus::PendingReview->value);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'date',
            'declared_amount' => 'decimal:2',
            'declared_currency' => Currency::class,
            'file_size' => 'integer',
            'status' => ReceiptStatus::class,
            'reviewed_at' => 'datetime',
            'rejection_reason_code' => RejectionReasonCode::class,
        ];
    }
}
