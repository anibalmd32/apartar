<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Ai\Agents\ReceiptReader;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PaymentReadingTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        config(['ai.providers.deepseek.key' => 'clave-de-prueba']);

        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_business_reads_a_capture_with_the_same_rules_as_the_customer(): void
    {
        ReceiptReader::fake([[
            'amount' => '1.275,00',
            'currency' => 'Bs',
            'paid_on' => '2026-10-08',
            'reference' => '0012 3456',
            'bank' => '0105',
        ]]);

        $this->read(UploadedFile::fake()->image('captura.png'), '2026-10-01', '2026-10-10')
            ->assertOk()
            ->assertExactJson(['reading' => [
                'amount' => '1275.00',
                'currency' => 'VES',
                'paidAt' => '2026-10-08',
                'reference' => '00123456',
                'bank' => 'Mercantil',
            ]]);

        ReceiptReader::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1 && $prompt->contains('2026-10-01'));
    }

    public function test_a_date_after_today_or_outside_the_range_is_dropped(): void
    {
        ReceiptReader::fake([['amount' => '30.00', 'currency' => 'USD', 'paid_on' => '2026-10-11', 'reference' => '', 'bank' => '']]);

        $this->read(UploadedFile::fake()->image('captura.png'), '2026-10-01', '2026-12-31')
            ->assertOk()
            ->assertJsonPath('reading.amount', '30.00')
            ->assertJsonPath('reading.currency', 'USD')
            ->assertJsonPath('reading.paidAt', null);
    }

    public function test_it_needs_the_file_and_a_valid_range(): void
    {
        ReceiptReader::fake();

        $this->actingAs($this->member)
            ->postJson(route('payments.read', ['desde' => '2026-10-10', 'hasta' => '2026-10-01']), ['file' => UploadedFile::fake()->image('c.png')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hasta');

        $this->actingAs($this->member)
            ->postJson(route('payments.read', ['desde' => '2026-10-01', 'hasta' => '2026-10-10']), [])
            ->assertJsonValidationErrors('file');

        ReceiptReader::assertNeverPrompted();
    }

    public function test_a_disabled_member_or_a_guest_cannot_read(): void
    {
        ReceiptReader::fake();
        $this->withinOrganization($this->organization, fn () => $this->member->forceFill(['disabled_at' => now()])->save());

        $this->read(UploadedFile::fake()->image('c.png'), '2026-10-01', '2026-10-10')->assertRedirect();

        auth()->logout();
        $this->postJson(route('payments.read', ['desde' => '2026-10-01', 'hasta' => '2026-10-10']), ['file' => UploadedFile::fake()->image('c.png')])
            ->assertUnauthorized();

        ReceiptReader::assertNeverPrompted();
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function read(UploadedFile $file, string $from, string $to): TestResponse
    {
        return $this->actingAs($this->member)->postJson(route('payments.read', ['desde' => $from, 'hasta' => $to]), ['file' => $file]);
    }
}
