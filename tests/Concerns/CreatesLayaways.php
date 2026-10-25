<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Actions\Layaway\ReserveStockAction;
use App\Domain\Layaway\LayawayStateMachine;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Money\Money;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Subscription;
use App\Support\LayawayCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesLayaways
{
    /**
     * @return list<LayawayStatus>
     */
    protected function pathTo(LayawayStatus $status): array
    {
        return match ($status) {
            LayawayStatus::PendingDownPayment => [],
            LayawayStatus::Active => [LayawayStatus::Active],
            LayawayStatus::Overdue => [LayawayStatus::Active, LayawayStatus::Overdue],
            LayawayStatus::Completed => [LayawayStatus::Active, LayawayStatus::Completed],
            LayawayStatus::Cancelled => [LayawayStatus::Cancelled],
            LayawayStatus::Expired => [LayawayStatus::Expired],
        };
    }

    protected function subscribe(Organization $organization): Organization
    {
        app(TenantContext::class)->runFor($organization, fn () => Subscription::factory()->create());

        return $organization;
    }

    protected function layawayIn(
        Organization $organization,
        LayawayStatus $status = LayawayStatus::PendingDownPayment,
        ?Product $product = null,
        int $quantity = 1,
        ?LayawayTerms $terms = null,
    ): Layaway {
        $terms ??= new LayawayTerms(new Money(20_000), new Money(5_000), $quantity, 5, 15);

        return app(TenantContext::class)->runFor($organization, fn (): Layaway => DB::transaction(function () use ($organization, $status, $product, $terms): Layaway {
            $quantity = $terms->quantity;
            $product ??= Product::factory()->create();

            app(ReserveStockAction::class)->handle($product, $quantity);

            app(LimitEnforcer::class)->adjust(UsageMetric::ActiveLayaways, 1);

            $customerId = DB::table('customers')->insertGetId([
                'organization_id' => $organization->getKey(),
                'document_number' => 'V'.random_int(10_000_000, 99_999_999),
                'document_kind' => 'cedula',
                'full_name' => 'Cliente de prueba',
                'phone' => '+584141234567',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $layaway = new Layaway;
            $layaway->forceFill([
                'code' => LayawayCode::generate(),
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $customerId,
                'product_id' => $product->getKey(),
                'quantity' => $quantity,
                'unit_price_cents' => $terms->unitPrice,
                'down_payment_cents' => $terms->downPayment,
                'installments_count' => $terms->installmentsCount,
                'frequency_days' => $terms->frequencyDays,
                'product_name_snapshot' => $product->name,
                'product_sku_snapshot' => $product->sku,
                'total_cents' => $terms->total(),
                'remaining_cents' => $terms->total(),
                'status' => LayawayStatus::PendingDownPayment,
            ])->save();

            foreach ($this->pathTo($status) as $step) {
                app(LayawayStateMachine::class)->transition($layaway, $step);
            }

            return $layaway;
        }));
    }
}
