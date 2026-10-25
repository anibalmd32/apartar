<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\TermsConsent;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ResolveCustomerConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-cliente-';

    private const DOCUMENT = 'V23456789';

    private ?Connection $rival = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('customers')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        $this->releaseRival();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->withoutOrganization();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_two_simultaneous_requests_with_the_same_new_document_leave_one_customer(): void
    {
        $organization = $this->seedOrganizations();

        $winnerId = $this->withinOrganization($organization, function () use ($organization): int {
            DB::beginTransaction();

            $this->assertFalse(Customer::query()->where('document_number', self::DOCUMENT)->exists());

            $rivalId = $this->rivalInserts($organization);

            $customer = app(ResolveCustomerAction::class)->handle(
                CustomerContactData::fromInput(self::DOCUMENT, 'Carmen Rodríguez', '0414-1112233', 'carmen@ejemplo.test'),
                TermsConsent::acceptedNowFrom('190.202.10.5'),
            );

            DB::commit();

            $this->assertSame($rivalId, $customer->getKey(), 'La acción no reutilizó la ficha que ganó la carrera.');

            return $rivalId;
        });

        $rows = DB::table('customers')
            ->where('organization_id', $organization->getKey())
            ->where('document_number', self::DOCUMENT)
            ->get();

        $this->assertCount(1, $rows);

        $this->assertSame($winnerId, (int) $rows[0]->id);
        $this->assertSame('+584141112233', $rows[0]->phone);
        $this->assertSame('190.202.10.5', $rows[0]->terms_ip_address);
        $this->assertSame(1, DB::table('activity_log')
            ->where('organization_id', $organization->getKey())
            ->where('event', AuditEvent::CustomerContactUpdated->value)
            ->where('subject_id', $winnerId)
            ->count());
    }

    public function test_without_a_race_the_new_document_is_simply_created(): void
    {
        $organization = $this->seedOrganizations();

        $customer = $this->withinOrganization($organization, fn (): Customer => app(ResolveCustomerAction::class)->handle(
            CustomerContactData::fromInput(self::DOCUMENT, 'Carmen Rodríguez', '0414-1112233', null),
            TermsConsent::acceptedNowFrom('190.202.10.5'),
        ));

        $this->assertTrue($customer->wasRecentlyCreated);
        $this->assertSame(0, DB::table('activity_log')->where('organization_id', $organization->getKey())->count());
    }

    private function seedOrganizations(): Organization
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $neighbour = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        DB::table('customers')->insert($this->row($neighbour, 'Vecina Con El Mismo Documento', '+584160000000'));

        return $organization;
    }

    private function rivalInserts(Organization $organization): int
    {
        $rival = $this->rival();

        $rival->beginTransaction();
        $id = (int) $rival->table('customers')->insertGetId($this->row($organization, 'Carmen Rodríguez', '+584149998877'));
        $rival->commit();

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Organization $organization, string $name, string $phone): array
    {
        return [
            'organization_id' => $organization->getKey(),
            'document_number' => self::DOCUMENT,
            'document_kind' => 'cedula',
            'full_name' => $name,
            'phone' => $phone,
            'terms_accepted_at' => now(),
            'terms_ip_address' => '186.14.0.9',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function rival(): Connection
    {
        if ($this->rival instanceof Connection) {
            return $this->rival;
        }

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);

        return $this->rival = DB::connection('mysql_rival');
    }

    private function releaseRival(): void
    {
        if (! $this->rival instanceof Connection) {
            return;
        }

        if ($this->rival->transactionLevel() > 0) {
            $this->rival->rollBack();
        }

        $this->rival->disconnect();
        $this->rival = null;

        DB::purge('mysql_rival');
    }

    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        DB::table('customers')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
