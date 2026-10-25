<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RoleIsReadFromDatabaseTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_a_promotion_applies_on_the_next_request(): void
    {
        $url = $this->probeRoute();
        $member = $this->seedUser();

        $this->login($member);

        $this->get($url)->assertForbidden();

        $this->writeRole($member, OrganizationRole::Owner);
        $this->nextRequest();

        $this->get($url)->assertOk();
    }

    public function test_a_demotion_applies_on_the_next_request(): void
    {
        $url = $this->probeRoute();
        $owner = $this->seedUser(OrganizationRole::Owner);

        $this->login($owner);

        $this->get($url)->assertOk();

        $this->writeRole($owner, OrganizationRole::Member);
        $this->nextRequest();

        $this->get($url)->assertForbidden();
    }

    public function test_the_session_holds_no_role(): void
    {
        $url = $this->probeRoute();
        $owner = $this->seedUser(OrganizationRole::Owner);

        $this->login($owner);
        $this->get($url)->assertOk();

        $payload = (string) json_encode(session()->all());

        $this->assertStringNotContainsString(OrganizationRole::Owner->value, $payload);
        $this->assertStringNotContainsString(OrganizationRole::Member->value, $payload);
        $this->assertStringNotContainsString($owner->email, $payload);
    }

    public function test_the_session_holds_only_the_user_identifier(): void
    {
        $owner = $this->seedUser(OrganizationRole::Owner);

        $this->login($owner);

        $this->assertAuthenticatedAs($owner);

        $stored = array_filter(
            session()->all(),
            fn (mixed $value): bool => $value === $owner->getKey(),
        );

        $this->assertNotEmpty($stored);
    }

    private function probeRoute(): string
    {
        Route::middleware(['web', 'auth', 'tenant'])->get('__roles/equipo', function (): array {
            Gate::authorize('viewAny', User::class);

            return ['ok' => true];
        });

        return '/__roles/equipo';
    }

    private function seedUser(OrganizationRole $role = OrganizationRole::Member): User
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->state(['role' => $role])->create(),
        );

        $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $user;
    }

    private function login(User $user): void
    {
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();
    }

    private function writeRole(User $user, OrganizationRole $role): void
    {
        DB::table('users')->where('id', $user->getKey())->update(['role' => $role->value]);
    }

    private function nextRequest(): void
    {
        $this->app->make('auth')->forgetGuards();
    }
}
