<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Payment\ReadReceiptAction;
use App\Ai\Agents\ReceiptReader;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReadReceiptActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        config(['ai.providers.deepseek.key' => 'clave-de-prueba']);

        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->layaway = $this->layawayIn($this->organization);
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->withoutOrganization();
        $this->actingAs($customer, 'customer');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_reads_the_capture_and_returns_clean_fields(): void
    {
        ReceiptReader::fake([[
            'amount' => '22231,38',
            'currency' => 'ves',
            'paid_on' => '2026-10-10',
            'reference' => ' 0012 3456 ',
            'bank' => 'Banco Mercantil',
        ]]);

        $this->read(UploadedFile::fake()->image('captura.png'))
            ->assertOk()
            ->assertExactJson(['reading' => [
                'amount' => '22231.38',
                'currency' => 'VES',
                'paidAt' => '2026-10-10',
                'reference' => '00123456',
                'bank' => 'Mercantil',
            ]]);

        ReceiptReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1);
    }

    public function test_what_does_not_make_sense_comes_back_empty(): void
    {
        ReceiptReader::fake([[
            'amount' => 'veinte',
            'currency' => 'EUR',
            'paid_on' => '2026-11-30',
            'reference' => '#',
            'bank' => 'Banco Inventado',
        ]]);

        $this->read(UploadedFile::fake()->image('captura.jpg'))
            ->assertOk()
            ->assertExactJson(['reading' => ['amount' => null, 'currency' => null, 'paidAt' => null, 'reference' => null, 'bank' => null]]);
    }

    public function test_a_failing_provider_returns_nothing_instead_of_an_error(): void
    {
        ReceiptReader::fake(fn () => throw new RuntimeException('se cayó'));

        $this->read(UploadedFile::fake()->image('captura.png'))
            ->assertOk()
            ->assertJsonPath('reading.amount', null);
    }

    public function test_a_pdf_or_a_business_without_ai_is_not_sent_to_read(): void
    {
        ReceiptReader::fake();

        $this->read(UploadedFile::fake()->create('pago.pdf', 50, 'application/pdf'))->assertOk()->assertJsonPath('reading.reference', null);

        config(['ai.providers.deepseek.key' => null]);
        $this->read(UploadedFile::fake()->image('captura.png'))->assertOk()->assertJsonPath('reading.reference', null);

        ReceiptReader::assertNeverPrompted();
    }

    public function test_the_daily_quota_of_the_business_stops_the_reading(): void
    {
        ReceiptReader::fake();
        $key = sprintf('ai-receipt:%d:%s', $this->organization->getKey(), '2026-10-10');

        for ($i = 0; $i < ReadReceiptAction::DAILY_READS_PER_ORGANIZATION; $i++) {
            RateLimiter::hit($key, 86_400);
        }

        $this->read(UploadedFile::fake()->image('captura.png'))->assertOk()->assertJsonPath('reading.amount', null);

        ReceiptReader::assertNeverPrompted();
    }

    public function test_a_layaway_that_no_longer_collects_or_a_guest_cannot_read(): void
    {
        ReceiptReader::fake();

        $this->post(route('portal.receipts.read', ['code' => $this->layaway->code]), [])->assertSessionHasErrors('file');

        $completed = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->withoutOrganization();
        $this->post(route('portal.receipts.read', ['code' => $completed->code]), ['file' => UploadedFile::fake()->image('c.png')])->assertNotFound();

        auth('customer')->logout();
        $this->post(route('portal.receipts.read', ['code' => $this->layaway->code]), ['file' => UploadedFile::fake()->image('c.png')])->assertRedirect();

        ReceiptReader::assertNeverPrompted();
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('portal.receipts.read', ['code' => $this->layaway->code]), ['file' => $file]);
    }
}
