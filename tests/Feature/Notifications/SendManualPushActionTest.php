<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notification\ManualPushData;
use App\Actions\Notification\SendManualPushAction;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class SendManualPushActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_segment_reuses_the_collection_filters_without_duplicates(): void
    {
        Queue::fake();
        $organization = $this->subscribe(Organization::factory()->create());
        $done = $this->layawayIn($organization, LayawayStatus::Completed);
        $again = $this->layawayIn($organization, LayawayStatus::Completed);
        DB::table('layaways')->where('id', $again->getKey())->update(['customer_id' => $done->customer_id]);
        $this->layawayIn($organization, LayawayStatus::Active);
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        Customer::query()->withoutGlobalScopes()->findOrFail($done->customer_id)->updatePushSubscription('https://push.test/1', 'k', 't', 'aes128gcm');

        $report = $this->withinOrganization($organization, fn (): array => app(SendManualPushAction::class)->handle(
            new ManualPushData('Ya está', 'Pasa a buscarlo.', null, null, ['preset' => 'completed_not_delivered']),
            $owner,
        ));

        $this->assertSame(['recipients' => 1, 'reachable' => 1, 'unreachable' => 0, 'queued' => 1], $report);
    }
}
