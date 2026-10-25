<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Account\PortalContext;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class PortalContextTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_without_a_session_it_sees_nothing(): void
    {
        $context = PortalContext::fromSession();

        $this->assertFalse($context->isScoped());
        $this->assertSame([], $context->customers());

        $this->expectException(ModelNotFoundException::class);
        $context->findLayaway('AP-7K3M9QRT');
    }

    public function test_the_scoped_session_sees_its_customer_and_only_its_layaways(): void
    {
        $organization = Organization::factory()->create();
        $mine = $this->layawayIn($organization);
        $foreign = $this->layawayIn($organization);

        $this->withinOrganization($organization, function () use ($mine, $foreign): void {
            $customer = Customer::query()->findOrFail($mine->customer_id);
            $this->actingAs($customer, 'customer');

            $context = PortalContext::fromSession();

            $this->assertTrue($context->isScoped());
            $this->assertCount(1, $context->customers());
            $this->assertTrue($context->findLayaway(strtolower($mine->code))->is($mine), 'El código se normaliza.');

            $this->expectException(ModelNotFoundException::class);
            $context->findLayaway($foreign->code);
        });
    }
}
