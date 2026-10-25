<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Organization\InviteTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class AcceptInvitationTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 10]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();

                foreach (UsageMetric::cases() as $metric) {
                    UsageCounter::factory()->forMetric($metric)->create();
                }
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_accepting_sets_the_password_and_lets_the_person_in(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->get('/invitacion/'.$invitation->plainToken)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/AcceptInvitation')
                ->where('email', $invited->email)
                ->where('role', $invited->role->value)
                ->where('expiresInHours', TeamInvitation::TTL_HOURS));

        $this->post('/invitacion/'.$invitation->plainToken, [
            'password' => 'Contrasena-Larga-9',
            'password_confirmation' => 'Contrasena-Larga-9',
        ])->assertRedirect('/backoffice');

        $invited->refresh();

        $this->assertNotNull($invited->password);
        $this->assertNull($invited->invitation_token_hash);
        $this->assertFalse($invited->must_change_password);
        $this->assertNotNull($invited->invited_at, 'Cuándo se le invitó es parte de su ficha, no del enlace.');
        $this->assertAuthenticatedAs($invited);
    }

    public function test_the_link_works_exactly_once(): void
    {
        [, $invitation] = $this->pendingInvitation();

        $this->post('/invitacion/'.$invitation->plainToken, [
            'password' => 'Contrasena-Larga-9',
            'password_confirmation' => 'Contrasena-Larga-9',
        ])->assertRedirect('/backoffice');

        $this->post('/backoffice/logout');

        $this->get('/invitacion/'.$invitation->plainToken)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/InvitationExpired'));
    }

    public function test_an_expired_link_shows_the_resend_screen_and_burns_the_token(): void
    {
        $invitation = $this->issue();

        $invited = $this->withinOrganization(
            $this->organization,
            fn (): User => User::factory()->withExpiredInvitation($invitation)->create(),
        );

        $this->get('/invitacion/'.$invitation->plainToken)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/InvitationExpired')
                ->where('organizationName', $this->organization->name));

        $this->assertNull($invited->refresh()->invitation_token_hash, 'El token caducado no quedó consumido.');

        $this->assertDatabaseHas('users', ['id' => $invited->getKey()]);
    }

    public function test_opening_a_live_link_does_not_burn_it(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->get('/invitacion/'.$invitation->plainToken)->assertOk();
        $this->get('/invitacion/'.$invitation->plainToken)->assertOk();

        $this->assertNotNull($invited->refresh()->invitation_token_hash);
    }

    public function test_resending_invalidates_the_previous_link(): void
    {
        [$invited, $first] = $this->pendingInvitation();

        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->withinOrganization(
            $this->organization,
            fn () => app(InviteTeamMemberAction::class)->resend($invited, $owner),
        );

        $this->get('/invitacion/'.$first->plainToken)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/InvitationExpired'));

        Notification::assertSentToTimes($invited, TeamInvitationNotification::class, 1);

        $this->assertNotNull($invited->refresh()->invitation_token_hash);
        $this->assertNotSame($first->tokenHash, $invited->invitation_token_hash);
    }

    public function test_resending_does_not_consume_another_slot(): void
    {
        [$invited] = $this->pendingInvitation();

        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->actingAs($owner)->post('/backoffice/equipo/'.$invited->getKey().'/reenviar');

        $this->assertSame(2, $this->withinOrganization(
            $this->organization,
            fn (): int => (int) User::query()->whereNull('disabled_at')->count(),
        ));
    }

    public function test_a_token_pointing_at_another_organization_opens_nothing(): void
    {
        [, $invitation] = $this->pendingInvitation();

        $forged = $this->neighbourOrganization()->getKey().'.'.Str::after($invitation->plainToken, '.');

        $this->get('/invitacion/'.$forged)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('backoffice/auth/InvitationExpired'));
    }

    public function test_a_malformed_token_shows_the_same_screen(): void
    {
        $this->get('/invitacion/esto-no-es-un-token')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/auth/InvitationExpired')
                ->where('organizationName', null));
    }

    public function test_a_weak_password_is_rejected(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->postJson('/invitacion/'.$invitation->plainToken, [
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull($invited->refresh()->password);
    }

    public function test_accepting_after_a_suspension_still_creates_the_access(): void
    {
        [$invited, $invitation] = $this->pendingInvitation();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->post('/invitacion/'.$invitation->plainToken, [
            'password' => 'Contrasena-Larga-9',
            'password_confirmation' => 'Contrasena-Larga-9',
        ])->assertRedirect('/backoffice');

        $this->assertNotNull($invited->refresh()->password);
        $this->assertAuthenticatedAs($invited);

        $this->get('/backoffice')->assertOk();
        $this->get('/backoffice/equipo')->assertForbidden();
    }

    /**
     * @return array{0: User, 1: TeamInvitation}
     */
    private function pendingInvitation(): array
    {
        $invitation = $this->issue();

        $invited = $this->withinOrganization(
            $this->organization,
            fn (): User => User::factory()->invited($invitation)->create(),
        );

        $this->withoutOrganization();

        return [$invited, $invitation];
    }

    private function issue(): TeamInvitation
    {
        return TeamInvitation::issueFor($this->organization);
    }
}
