<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Money;
use App\Models\Installment;
use App\Models\Layaway;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Installment> */
    protected $model = Installment::class;

    /**
     * @return array<model-property<Installment>, mixed>
     */
    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('organization_id'),
            'number' => 1,
            'due_date' => now()->addDays(15)->toDateString(),
            'amount_cents' => new Money(3_000),
            'paid_cents' => new Money(0),
        ];
    }
}
