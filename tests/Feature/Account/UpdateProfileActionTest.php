<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\UpdateProfileAction;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class UpdateProfileActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_the_name_travels_to_every_membership_without_touching_the_verification(): void
    {
        Notification::fake();
        [$first, $second, $stranger] = $this->people();

        app(UpdateProfileAction::class)->handle($first, '  Mariela Rojas ', 'mariela@ejemplo.test');

        $this->assertSame('Mariela Rojas', $first->refresh()->name);
        $this->assertSame('Mariela Rojas', $this->fresh($second)->name);
        $this->assertNotNull($this->fresh($second)->email_verified_at);
        $this->assertSame('Ana', $this->fresh($stranger)->name);
        Notification::assertNothingSent();
    }

    public function test_a_new_email_is_lowercased_unverified_everywhere_and_confirmed_once(): void
    {
        Notification::fake();
        [$first, $second, $stranger] = $this->people();

        app(UpdateProfileAction::class)->handle($first, 'Mariela', ' MARIELA@nuevo.test ');

        $this->assertSame('mariela@nuevo.test', $first->refresh()->email);
        $this->assertSame('mariela@nuevo.test', $this->fresh($second)->email);
        $this->assertNull($first->email_verified_at);
        $this->assertNull($this->fresh($second)->email_verified_at);
        $this->assertSame('ana@ejemplo.test', $this->fresh($stranger)->email);
        Notification::assertSentToTimes($first, VerifyEmail::class, 1);
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function people(): array
    {
        $one = $this->subscribe(Organization::factory()->create());
        $two = $this->subscribe(Organization::factory()->create());
        $first = $this->withinOrganization($one, fn (): User => User::factory()->owner()->create(['email' => 'mariela@ejemplo.test']));
        $second = $this->withinOrganization($two, fn (): User => User::factory()->create(['email' => 'mariela@ejemplo.test', 'role' => OrganizationRole::Member]));
        $stranger = $this->withinOrganization($two, fn (): User => User::factory()->create(['email' => 'ana@ejemplo.test', 'name' => 'Ana']));
        $this->withoutOrganization();

        return [$first, $second, $stranger];
    }

    private function fresh(User $user): User
    {
        return User::withoutTenantScope()->findOrFail($user->getKey());
    }
}
