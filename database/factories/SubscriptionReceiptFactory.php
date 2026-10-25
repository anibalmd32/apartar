<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubscriptionReceipt>
 */
class SubscriptionReceiptFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<SubscriptionReceipt> */
    protected $model = SubscriptionReceipt::class;

    /**
     * @return array<model-property<SubscriptionReceipt>, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_invoice_id' => SubscriptionInvoice::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('subscription_invoices')->where('id', $attributes['subscription_invoice_id'])->value('organization_id'),
            'submitted_by' => User::factory()->owner(),
            'payment_method' => PaymentMethod::PagoMovil,
            'bank' => 'Banesco',
            'reference' => fake()->unique()->numerify('##########'),
            'paid_at' => now()->toDateString(),
            'declared_amount' => '15.00',
            'declared_currency' => Currency::USD,
            'file_path' => fn (array $attributes): string => SubscriptionReceipt::DIRECTORY.'/'.ReceiptFile::pathFor((int) $attributes['organization_id'], 'jpg'),
            'file_hash' => hash('sha256', (string) Str::uuid()),
            'file_mime' => 'image/jpeg',
            'file_size' => 120_000,
            'status' => ReceiptStatus::PendingReview,
        ];
    }
}
