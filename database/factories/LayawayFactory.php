<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Support\LayawayCode;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<Layaway>
 */
class LayawayFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Layaway> */
    protected $model = Layaway::class;

    /**
     * @return array<model-property<Layaway>, mixed>
     */
    public function definition(): array
    {
        $context = app(TenantContext::class);
        $organization = $context->hasOrganization() ? $context->organization() : Organization::factory()->create();

        /** @var Product $product */
        $product = $context->runFor($organization, fn (): Product => Product::factory()->createOne());

        $customerId = DB::table('customers')->insertGetId([
            'organization_id' => $organization->getKey(),
            'document_number' => 'V'.random_int(10_000_000, 99_999_999),
            'document_kind' => 'cedula',
            'full_name' => fake()->name(),
            'phone' => '+58414'.fake()->numerify('#######'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'organization_id' => $organization->getKey(),
            'code' => LayawayCode::generate(),
            'idempotency_key' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'product_id' => $product->getKey(),
            'quantity' => 1,
            'unit_price_cents' => new Money(20_000),
            'down_payment_cents' => new Money(5_000),
            'installments_count' => 5,
            'frequency_days' => 15,
            'product_name_snapshot' => $product->name,
            'product_sku_snapshot' => $product->sku,
            'total_cents' => new Money(20_000),
            'remaining_cents' => new Money(20_000),
            'status' => LayawayStatus::PendingDownPayment,
        ];
    }
}
