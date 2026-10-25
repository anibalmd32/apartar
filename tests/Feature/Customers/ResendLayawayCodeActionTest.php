<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Actions\Customer\ResendLayawayCodeAction;
use App\Domain\Customer\NoEmailToResendException;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LayawayCodeNotification;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class ResendLayawayCodeActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private Layaway $layaway;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->withoutOrganization();
    }

    public function test_the_code_goes_to_the_customers_own_email(): void
    {
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['email' => 'maria@example.com']);

        $email = $this->resend($this->member);

        $this->assertSame('maria@example.com', $email);
        Notification::assertSentOnDemand(LayawayCodeNotification::class, fn (LayawayCodeNotification $notification, array $channels, object $notifiable): bool => $notification->code === $this->layaway->code
            && $notification->businessName === 'Ferretería Oriente'
            && $notification->portalUrl === route('portal.access')
            && $notifiable->routes['mail'] === 'maria@example.com');
    }

    public function test_without_an_email_nothing_is_sent(): void
    {
        $this->expectException(NoEmailToResendException::class);

        try {
            $this->resend($this->member);
        } finally {
            Notification::assertNothingSent();
        }
    }

    public function test_someone_from_another_business_cannot_resend(): void
    {
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['email' => 'maria@example.com']);
        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->create());

        $this->expectException(AuthorizationException::class);
        $this->resend($stranger);
    }

    public function test_resending_from_the_profile_is_rate_limited_per_layaway(): void
    {
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['email' => 'maria@example.com']);
        $this->actingAs($this->member)->from(route('layaways.show', $this->layaway));

        for ($i = 0; $i < AppServiceProvider::CODE_RESENDS_PER_HOUR; $i++) {
            $this->post(route('layaways.resend-code', $this->layaway))->assertSessionHas('status');
        }

        $this->post(route('layaways.resend-code', $this->layaway))->assertTooManyRequests();
        Notification::assertSentOnDemandTimes(LayawayCodeNotification::class, AppServiceProvider::CODE_RESENDS_PER_HOUR);
    }

    private function resend(User $actor): string
    {
        return $this->withinOrganization($this->organization, fn (): string => app(ResendLayawayCodeAction::class)->handle($this->layaway, $actor));
    }
}
