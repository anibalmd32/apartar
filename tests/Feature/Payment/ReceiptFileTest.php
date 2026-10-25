<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use App\Policies\ReceiptPolicy;
use App\Support\SignedFileUrl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReceiptFileTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    private const CONTENT = "\x89PNG\r\n\x1a\nla captura del pago móvil";

    private Organization $organization;

    private Customer $customer;

    private Receipt $receipt;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);

        $this->neighbourOrganization();
        $this->organization = $this->subscribe(Organization::factory()->create());
        [$this->customer, $this->receipt, $this->layaway] = $this->receiptWithFile($this->organization);
        $this->withoutOrganization();
    }

    public function test_the_owner_sees_the_file_inline_and_uncached(): void
    {
        $response = $this->actingAs($this->customer, 'customer')->get(SignedFileUrl::forCustomer($this->receipt));

        $this->assertServesTheFile($response);
    }

    public function test_a_member_of_the_business_sees_the_file_through_the_backoffice(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $response = $this->actingAs($member)->get(SignedFileUrl::forBackoffice($this->receipt));

        $this->assertServesTheFile($response);
    }

    public function test_an_unsigned_url_is_forbidden(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->customer, 'customer')
            ->get(route(SignedFileUrl::CUSTOMER_ROUTE, ['receipt' => $this->receipt->getKey()]))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route(SignedFileUrl::BACKOFFICE_ROUTE, ['receipt' => $this->receipt->getKey()]))
            ->assertForbidden();
    }

    public function test_an_expired_customer_url_is_forbidden_right_after_its_ttl(): void
    {
        $this->freezeSecond();
        $url = SignedFileUrl::forCustomer($this->receipt);
        $this->actingAs($this->customer, 'customer');

        $this->travel(SignedFileUrl::CUSTOMER_TTL_MINUTES)->minutes();
        $this->get($url)->assertOk();

        $this->travel(1)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_an_expired_backoffice_url_is_forbidden_right_after_its_ttl(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
        $this->freezeSecond();
        $url = SignedFileUrl::forBackoffice($this->receipt);
        $this->actingAs($member);

        $this->travel(SignedFileUrl::CUSTOMER_TTL_MINUTES + 1)->minutes();
        $this->get($url)->assertOk();

        $this->travel(SignedFileUrl::BACKOFFICE_TTL_MINUTES - SignedFileUrl::CUSTOMER_TTL_MINUTES - 1)->minutes();
        $this->get($url)->assertOk();

        $this->travel(1)->seconds();
        $this->get($url)->assertForbidden();
    }

    public function test_a_tampered_url_is_forbidden(): void
    {
        $other = $this->receiptFor($this->layaway);
        $this->withoutOrganization();
        $url = SignedFileUrl::forCustomer($this->receipt);
        $this->actingAs($this->customer, 'customer');

        $swapped = str_replace(
            '/comprobantes/'.$this->receipt->getKey().'/',
            '/comprobantes/'.$other->getKey().'/',
            $url,
        );
        $this->assertNotSame($url, $swapped);
        $this->get($swapped)->assertForbidden();

        $expires = (int) $this->queryOf($url)['expires'];
        $this->get(str_replace('expires='.$expires, 'expires='.($expires + 3600), $url))->assertForbidden();

        $signature = $this->queryOf($url)['signature'];
        $this->get(str_replace($signature, strrev($signature), $url))->assertForbidden();

        $this->get($url)->assertOk();
    }

    public function test_a_valid_url_of_another_customer_of_the_same_business_is_forbidden(): void
    {
        [$stranger] = $this->receiptWithFile($this->organization);
        $this->withoutOrganization();

        $this->actingAs($stranger, 'customer')
            ->get(SignedFileUrl::forCustomer($this->receipt))
            ->assertForbidden();
    }

    public function test_a_missing_file_is_not_found_logged_and_leaves_the_receipt_alone(): void
    {
        Storage::disk(Receipt::DISK)->delete($this->receipt->file_path);
        $before = (array) DB::table('receipts')->where('id', $this->receipt->getKey())->first();
        $logger = Log::spy();

        $this->actingAs($this->customer, 'customer')
            ->get(SignedFileUrl::forCustomer($this->receipt))
            ->assertNotFound();

        $logger->shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'receipt.file_missing'
                && $context['receipt_id'] === $this->receipt->getKey()
                && $context['file_path'] === $this->receipt->file_path,
        );
        $this->assertSame($before, (array) DB::table('receipts')->where('id', $this->receipt->getKey())->first());
    }

    public function test_the_portal_route_requires_a_portal_session(): void
    {
        $this->get(SignedFileUrl::forCustomer($this->receipt))->assertRedirect(route('portal.access'));

        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($member)->get(SignedFileUrl::forCustomer($this->receipt))->assertRedirect(route('portal.access'));
    }

    public function test_the_backoffice_route_requires_a_team_session(): void
    {
        $default = Auth::getDefaultDriver();
        $this->actingAs($this->customer, 'customer');
        Auth::shouldUse($default);

        $this->get(SignedFileUrl::forBackoffice($this->receipt))->assertRedirect(route('login'));
    }

    public function test_the_backoffice_route_survives_the_suspension(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertServesTheFile($this->actingAs($member)->get(SignedFileUrl::forBackoffice($this->receipt)));
    }

    public function test_the_policy_follows_the_receipts_capability_of_each_role(): void
    {
        $policy = new ReceiptPolicy;

        foreach (OrganizationRole::cases() as $role) {
            $user = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['role' => $role]));

            $this->assertSame($role->can(OrganizationCapability::Receipts), $policy->view($user, $this->receipt), $role->value);
        }

        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->assertFalse($policy->view($foreign, $this->receipt));
        $this->assertTrue($policy->view($this->customer, $this->receipt));
    }

    /**
     * @return array{0: Customer, 1: Receipt, 2: Layaway}
     */
    private function receiptWithFile(Organization $organization): array
    {
        $layaway = $this->layawayIn($organization);
        $path = $organization->getKey().'/'.Str::uuid().'.png';
        Storage::disk(Receipt::DISK)->put($path, self::CONTENT);

        $receipt = $this->receiptFor($layaway, ReceiptStatus::PendingReview, [
            'file_path' => $path,
            'file_mime' => 'image/png',
            'file_size' => strlen(self::CONTENT),
        ]);
        $customer = $this->withinOrganization($organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));

        return [$customer, $receipt, $layaway];
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function assertServesTheFile(TestResponse $response): void
    {
        $response->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
