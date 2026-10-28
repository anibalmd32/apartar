<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Payment\PaymentMethod;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\DocumentPrefix;
use App\Models\MobilePrefix;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Rules\ValidVenezuelanPhone;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class VenezuelanCatalogsTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_the_catalogs_come_with_every_bank_and_mobile_prefix_and_their_logo_files_exist(): void
    {
        $this->assertSame(33, Bank::query()->count());
        $this->assertSame(['0412', '0414', '0416', '0422', '0424', '0426'], MobilePrefix::query()->orderBy('prefix')->pluck('prefix')->all());
        $this->assertSame('Banesco', Bank::query()->where('code', '0134')->value('name'));
        $this->assertSame(0, Bank::query()->whereNull('logo_path')->count());
        $this->assertSame(['V', 'E', 'J'], DocumentPrefix::query()->orderBy('position')->pluck('code')->all());

        $paths = [...Bank::query()->whereNotNull('logo_path')->pluck('logo_path'), ...MobilePrefix::query()->pluck('logo_path')];

        foreach ($paths as $path) {
            $this->assertFileExists(public_path((string) $path), (string) $path);
        }
    }

    public function test_a_bank_is_found_by_name_code_or_common_spelling(): void
    {
        $this->assertSame('0134', Bank::matching('  banesco ')?->code);
        $this->assertSame('0102', Bank::matching('Venezuela')?->code);
        $this->assertSame('0108', Bank::matching('BBVA Provincial')?->code);
        $this->assertSame('0128', Bank::matching('Banco Caroni')?->code);
        $this->assertSame('0105', Bank::matching('0105')?->code);
        $this->assertNull(Bank::matching('Bank of America'));
        $this->assertSame('Mercantil', Bank::canonicalName('banco mercantil'));
        $this->assertSame('Bank of America', Bank::canonicalName(' Bank of America '));
    }

    public function test_saving_a_payment_account_relates_its_bank_and_its_mobile_prefix(): void
    {
        $organization = Organization::factory()->create();

        [$pagoMovil, $transfer, $zelle] = $this->withinOrganization($organization, fn (): array => [
            PaymentAccount::factory()->create(['method' => PaymentMethod::PagoMovil, 'bank' => 'banesco', 'identifier' => '0424-1234567']),
            PaymentAccount::factory()->transferencia()->create(['bank' => 'Venezuela']),
            PaymentAccount::factory()->zelle()->create(['bank' => 'Bank of America']),
        ]);

        $this->assertSame(['Banesco', Bank::query()->where('code', '0134')->value('id')], [$pagoMovil->bank, $pagoMovil->bank_id]);
        $this->assertSame(MobilePrefix::query()->where('prefix', '0424')->value('id'), $pagoMovil->mobile_prefix_id);
        $this->assertSame(['Banco de Venezuela', null], [$transfer->bank, $transfer->mobile_prefix_id]);
        $this->assertSame(['Bank of America', null], [$zelle->bank, $zelle->bank_id]);

        $this->withinOrganization($organization, fn () => $pagoMovil->forceFill(['identifier' => '04121234567'])->save());
        $this->assertSame(MobilePrefix::query()->where('prefix', '0412')->value('id'), $pagoMovil->refresh()->mobile_prefix_id);
    }

    public function test_a_customer_and_a_business_phone_relate_their_mobile_prefix(): void
    {
        $organization = Organization::factory()->create(['phone' => '0416-1234567']);
        $customer = $this->withinOrganization($organization, fn (): Customer => Customer::factory()->create(['phone' => '+584261234567']));
        $landline = $this->withinOrganization($organization, fn (): Customer => Customer::factory()->create(['phone' => '+582121234567']));

        $this->assertSame('0416', $organization->mobilePrefix?->prefix);
        $this->assertSame('0426', $customer->mobilePrefix?->prefix);
        $this->assertNull($landline->mobile_prefix_id);
    }

    public function test_only_the_six_mobile_prefixes_are_accepted_as_a_phone(): void
    {
        $passes = fn (string $phone): bool => Validator::make(['phone' => $phone], ['phone' => [new ValidVenezuelanPhone]])->passes();

        $this->assertTrue($passes('0414-1234567'));
        $this->assertTrue($passes('04221234567'));
        $this->assertFalse($passes('0212-1234567'));
        $this->assertFalse($passes('0415-1234567'));
        $this->assertSame(
            [ValidVenezuelanPhone::MOBILE_ONLY],
            Validator::make(['phone' => '0212-5551234'], ['phone' => [new ValidVenezuelanPhone]])->errors()->get('phone'),
        );
    }

    public function test_the_catalogs_are_shared_with_the_screens(): void
    {
        $catalogs = HandleInertiaRequests::catalogs();

        $this->assertCount(33, $catalogs['banks']);
        $this->assertSame(['id', 'code', 'name', 'logoUrl'], array_keys($catalogs['banks'][0]));
        $this->assertSame(['id', 'prefix', 'carrier', 'logoUrl'], array_keys($catalogs['mobilePrefixes'][0]));
        $this->assertStringEndsWith('/images/carriers/icons/digitel.svg', (string) $catalogs['mobilePrefixes'][0]['logoUrl']);
        $this->assertSame([['id' => 1, 'code' => 'V', 'name' => 'Venezolano'], ['id' => 2, 'code' => 'E', 'name' => 'Extranjero'], ['id' => 3, 'code' => 'J', 'name' => 'Jurídico']], $catalogs['documentPrefixes']);
    }
}
