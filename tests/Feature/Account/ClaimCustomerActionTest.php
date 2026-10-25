<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Actions\Account\ClaimCustomerAction;
use App\Domain\Account\ClaimVerificationFailedException;
use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ClaimCustomerActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const DOCUMENT = 'V12345678';

    private Organization $organization;

    private Account $account;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->account = Account::factory()->create();
        $this->layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['document_number' => self::DOCUMENT]);
        $this->withoutOrganization();
    }

    public function test_document_and_code_link_the_record(): void
    {
        $customer = $this->claim(self::DOCUMENT, $this->layaway->code);

        $this->assertSame((int) $this->layaway->customer_id, (int) $customer->getKey());
        $this->assertSame((int) $this->account->getKey(), (int) $this->accountIdOf($this->layaway));
    }

    public function test_the_document_alone_never_links(): void
    {
        $someoneElses = $this->layawayIn($this->organization);
        $this->withoutOrganization();

        foreach (['AP-NOEXISTE', 'no-es-un-codigo', $someoneElses->code] as $code) {
            $this->expectVerificationFailure(self::DOCUMENT, $code);
        }

        $this->assertNull($this->accountIdOf($this->layaway));
        $this->assertNull($this->accountIdOf($someoneElses));
    }

    public function test_a_good_code_with_another_document_fails(): void
    {
        $this->expectVerificationFailure('V87654321', $this->layaway->code);

        $this->assertNull($this->accountIdOf($this->layaway));
    }

    public function test_every_failure_reads_the_same(): void
    {
        $messages = [];

        foreach ([[self::DOCUMENT, 'AP-NOEXISTE'], ['V87654321', $this->layaway->code], [self::DOCUMENT, 'basura']] as [$document, $code]) {
            try {
                $this->claim($document, $code);
            } catch (ClaimVerificationFailedException $failure) {
                $messages[] = $failure->getMessage();
            }
        }

        $this->assertSame([ClaimVerificationFailedException::MESSAGE], array_values(array_unique($messages)));
        $this->assertCount(3, $messages);
    }

    public function test_a_record_of_another_account_is_refused(): void
    {
        $other = Account::factory()->create();
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['account_id' => $other->getKey()]);

        $this->expectException(CustomerAlreadyLinkedException::class);

        try {
            $this->claim(self::DOCUMENT, $this->layaway->code);
        } finally {
            $this->assertSame((int) $other->getKey(), (int) $this->accountIdOf($this->layaway));
        }
    }

    public function test_a_second_record_in_the_same_business_is_refused(): void
    {
        $first = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $first->customer_id)->update(['account_id' => $this->account->getKey()]);
        $this->withoutOrganization();

        $this->expectException(CustomerAlreadyLinkedException::class);

        $this->claim(self::DOCUMENT, $this->layaway->code);
    }

    public function test_a_suspended_business_and_a_finished_layaway_can_be_claimed(): void
    {
        $suspended = $this->subscribe(Organization::factory()->suspended()->create());
        $finished = $this->layawayIn($suspended, LayawayStatus::Cancelled);
        DB::table('customers')->where('id', $finished->customer_id)->update(['document_number' => self::DOCUMENT]);
        $this->withoutOrganization();

        $this->claim(self::DOCUMENT, $finished->code);

        $this->assertSame((int) $this->account->getKey(), (int) $this->accountIdOf($finished));
    }

    public function test_claiming_twice_is_harmless(): void
    {
        $this->claim(self::DOCUMENT, $this->layaway->code);
        $this->withoutOrganization();

        $this->assertSame((int) $this->layaway->customer_id, (int) $this->claim(self::DOCUMENT, $this->layaway->code)->getKey());
    }

    private function claim(string $document, string $code): Customer
    {
        return app(ClaimCustomerAction::class)->handle($this->account, DocumentNumber::fromInput($document), $code);
    }

    private function expectVerificationFailure(string $document, string $code): void
    {
        try {
            $this->claim($document, $code);
            $this->fail("Vinculó con el documento {$document} y el código {$code}.");
        } catch (ClaimVerificationFailedException) {
            $this->addToAssertionCount(1);
        }
    }

    private function accountIdOf(Layaway $layaway): ?int
    {
        $value = DB::table('customers')->where('id', $layaway->customer_id)->value('account_id');

        return $value === null ? null : (int) $value;
    }
}
