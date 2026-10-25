<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanDowngradeNeedsConfirmationException;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class PlanDowngradeNeedsConfirmationExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_carries_the_preview_and_names_the_target(): void
    {
        $free = Plan::factory()->create(['code' => 'free', 'name' => 'Free', 'position' => 1]);
        $organization = Organization::factory()->create();

        $preview = $this->withinOrganization($organization, function () use ($free): PlanChangePreview {
            Subscription::factory()->forPlan(Plan::factory()->create(['position' => 2]))->paid()->create();

            return PlanChangePreview::forDowngrade(Subscription::query()->with('plan')->firstOrFail(), $free);
        });

        $exception = new PlanDowngradeNeedsConfirmationException($preview);

        $this->assertSame($preview, $exception->preview);
        $this->assertStringContainsString('Free', $exception->getMessage());
        $this->assertStringContainsString('confírmalo', $exception->getMessage());
        $this->assertSame(422, PlanDowngradeNeedsConfirmationException::STATUS);
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }
}
