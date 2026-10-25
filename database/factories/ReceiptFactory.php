<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Layaway;
use App\Models\Receipt;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Receipt> */
    protected $model = Receipt::class;

    /**
     * @return array<model-property<Receipt>, mixed>
     */
    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('organization_id'),
            'customer_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('customer_id'),
            'payment_account_id' => null,
            'payment_method' => PaymentMethod::PagoMovil,
            'bank' => 'Banesco',
            'reference' => fake()->unique()->numerify('##########'),
            'paid_at' => now()->toDateString(),
            'declared_amount' => '850.00',
            'declared_currency' => Currency::VES,
            'note' => null,
            'file_path' => fn (array $attributes): string => ReceiptFile::pathFor((int) $attributes['organization_id'], 'jpg'),
            'file_hash' => hash('sha256', (string) Str::uuid()),
            'file_mime' => 'image/jpeg',
            'file_size' => 120_000,
            'status' => ReceiptStatus::PendingReview,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (): array => [
            'payment_method' => PaymentMethod::EfectivoUsd,
            'bank' => null,
            'reference' => null,
            'declared_amount' => '30.00',
            'declared_currency' => Currency::USD,
        ]);
    }

    public function withStatus(ReceiptStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
