<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $neighbour = $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $plan = Plan::factory()->create();

        foreach ([$neighbour, $this->organization] as $organization) {
            $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan($plan)->create());
        }

        $this->withinOrganization($neighbour, fn () => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_the_page_carries_the_entries_and_the_filter_options(): void
    {
        $owner = $this->owner();

        $this->writeEntries($owner, 3);

        $this->actingAs($owner)
            ->get('/backoffice/auditoria')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/audit/Index')
                ->has('rows', 3)
                ->has('events', 6)
                ->where('events.3.area', 'organization')
                ->where('meta.total', 3)
                ->where('timezone', $this->organization->timezone));
    }

    public function test_the_listing_is_paginated_by_the_server(): void
    {
        $owner = $this->owner();

        $this->writeEntries($owner, 30);

        $this->actingAs($owner)
            ->get('/backoffice/auditoria')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 25)
                ->where('meta.total', 30)
                ->where('meta.lastPage', 2));

        $this->actingAs($owner)
            ->get('/backoffice/auditoria?page=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 5)->where('meta.page', 2));
    }

    public function test_the_type_filter_narrows_the_listing(): void
    {
        $owner = $this->owner();

        $this->writeEntries($owner, 2, AuditEvent::SettingsUpdated);
        $this->writeEntries($owner, 1, AuditEvent::IdentityUpdated);

        $this->actingAs($owner)
            ->get('/backoffice/auditoria?event='.AuditEvent::IdentityUpdated->value)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('meta.filters.event', AuditEvent::IdentityUpdated->value));
    }

    public function test_the_actor_filter_narrows_the_listing(): void
    {
        $owner = $this->owner();
        $other = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->writeEntries($owner, 2);
        $this->writeEntries($other, 3);

        $this->actingAs($owner)
            ->get('/backoffice/auditoria?actor='.$other->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 3)->where('meta.total', 3));
    }

    public function test_the_date_range_narrows_the_listing(): void
    {
        $owner = $this->owner();

        $this->writeEntries($owner, 1);

        $this->withinOrganization($this->organization, function (): void {
            AuditEntry::query()->firstOrFail()->forceFill(['created_at' => now()->subMonth()])->save();
        });

        $this->writeEntries($owner, 2);

        $this->actingAs($owner)
            ->get('/backoffice/auditoria?from='.now()->subWeek()->toDateString())
            ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 2));
    }

    public function test_an_unknown_filter_is_ignored_instead_of_failing(): void
    {
        $owner = $this->owner();

        $this->writeEntries($owner, 2);

        $this->actingAs($owner)
            ->get('/backoffice/auditoria?event=no-existe&from=manzana')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('meta.filters.event', null)
                ->where('meta.filters.from', null)
                ->where('meta.ignoredFilters', ['event', 'from']));
    }

    public function test_an_actor_from_another_organization_is_ignored(): void
    {
        $owner = $this->owner();
        $this->writeEntries($owner, 2);

        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->actingAs($owner)
            ->get('/backoffice/auditoria?actor='.$foreign->getKey())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 2)
                ->where('meta.filters.actor', null)
                ->where('meta.ignoredFilters', ['actor']));
    }

    public function test_a_member_cannot_open_the_audit_log(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->actingAs($member)->get('/backoffice/auditoria')->assertForbidden();
    }

    public function test_the_audit_log_exposes_no_route_that_changes_it(): void
    {
        $mutating = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_contains($route->uri(), 'auditoria')) {
                continue;
            }

            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                $mutating[] = $method.' '.$route->uri();
            }
        }

        $this->assertSame([], $mutating, 'La auditoría es append-only: '.implode(', ', $mutating));
    }

    public function test_the_audit_log_does_not_survive_a_suspension(): void
    {
        $this->organization->forceFill([
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ])->save();

        $this->actingAs($this->owner())->get('/backoffice/auditoria')->assertForbidden();
    }

    private function writeEntries(User $actor, int $count, ?AuditEvent $event = null): void
    {
        $event ??= AuditEvent::SettingsUpdated;

        $this->withinOrganization($this->organization, function () use ($actor, $count, $event): void {
            foreach (range(1, $count) as $index) {
                AuditEntry::query()->create([
                    'log_name' => AuditEntry::LOG_NAME,
                    'event' => $event->value,
                    'description' => $event->label().' #'.$index,
                    'causer_type' => $actor->getMorphClass(),
                    'causer_id' => $actor->getKey(),
                    'properties' => ['changed' => []],
                ]);
            }
        });
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }
}
