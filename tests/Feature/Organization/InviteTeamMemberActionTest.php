<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\InviteTeamMemberAction;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\InvitationNotPendingException;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TeamInvitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class InviteTeamMemberActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create(['max_users' => 5]);

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, function () use ($plan): void {
                Subscription::factory()->forPlan($plan)->create();
                UsageCounter::factory()->forMetric(UsageMetric::Users)->create();
            });
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->actingAsOrganization($this->organization);
    }

    public function test_only_the_owner_may_invite(): void
    {
        $member = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $member);
    }

    public function test_the_stored_token_is_the_hash_of_the_emailed_link(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $owner);

        Notification::assertSentTo(
            $invited,
            TeamInvitationNotification::class,
            function (TeamInvitationNotification $notification) use ($invited): bool {
                $url = $notification->toMail($invited)->actionUrl;
                $token = urldecode(Str::afterLast($url, '/'));

                return TeamInvitation::hash($token) === $invited->invitation_token_hash;
            },
        );

        $this->assertNotSame($invited->invitation_token_hash, $invited->getKey());
    }

    public function test_the_invitation_is_born_with_its_deadline(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $owner);

        $this->assertNotNull($invited->invitation_expires_at);
        $this->assertTrue($invited->invitation_expires_at->isFuture());
        $this->assertTrue($invited->invitation_expires_at->lessThanOrEqualTo(
            now()->addHours(TeamInvitation::TTL_HOURS),
        ));
    }

    public function test_an_accepted_member_is_neither_resent_nor_revoked(): void
    {
        $owner = User::factory()->owner()->create();
        $member = User::factory()->create();

        try {
            $this->action()->resend($member, $owner);
            $this->fail('Reenviar a quien ya aceptó debió fallar.');
        } catch (InvitationNotPendingException) {
        }

        $this->expectException(InvitationNotPendingException::class);

        $this->action()->revoke($member, $owner);
    }

    public function test_someone_may_be_invited_straight_as_an_owner(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Socia', 'socia@ejemplo.test', OrganizationRole::Owner, $owner);

        $this->assertSame(OrganizationRole::Owner, $invited->role);
        $this->assertNull($invited->password, 'Ni siquiera un owner invitado entra sin fijar su contraseña.');
    }

    public function test_the_unique_index_rejects_a_duplicate_email_without_the_request(): void
    {
        $owner = User::factory()->owner()->create();

        User::factory()->create(['email' => 'repetida@ejemplo.test']);

        try {
            $this->action()->handle('Repetida', 'repetida@ejemplo.test', OrganizationRole::Member, $owner);
            $this->fail('El correo duplicado debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertSame(2, (int) User::query()->count());
    }

    public function test_the_invited_person_lands_in_the_active_organization(): void
    {
        $owner = User::factory()->owner()->create();

        $invited = $this->action()->handle('Marta', 'marta@ejemplo.test', OrganizationRole::Member, $owner);

        $this->assertSame($this->organization->getKey(), $invited->organization_id);
    }

    private function action(): InviteTeamMemberAction
    {
        return app(InviteTeamMemberAction::class);
    }
}
