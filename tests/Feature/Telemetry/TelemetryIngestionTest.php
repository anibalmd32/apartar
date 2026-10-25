<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Domain\Telemetry\IpHasher;
use App\Http\Controllers\Api\TelemetryController;
use App\Jobs\ProcessTelemetryBatch;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TelemetryIngestionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const VISITOR = '0190f0f0-0000-7000-8000-000000000001';

    public function test_the_ip_is_never_stored_in_clear(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '190.12.34.56'])
            ->postJson(route('api.telemetry'), ['visitorId' => self::VISITOR, 'sessionId' => 'sesion-abc-123', 'events' => [['name' => 'feed_viewed']]])
            ->assertNoContent();

        $row = (array) DB::table('telemetry_events')->sole();

        $this->assertSame(IpHasher::hash('190.12.34.56'), $row['ip_hash']);
        $this->assertStringNotContainsString('190.12.34.56', (string) json_encode($row));
    }

    public function test_a_batch_is_queued_and_nothing_is_written(): void
    {
        Queue::fake();
        DB::enableQueryLog();

        $this->send([['name' => 'catalog_viewed', 'catalogSlug' => 'tienda']])->assertNoContent();

        Queue::assertPushedOn(ProcessTelemetryBatch::QUEUE, ProcessTelemetryBatch::class);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains((string) $query['query'], 'telemetry_events')));
        $this->assertSame(0, DB::table('telemetry_events')->count());
    }

    public function test_an_injected_organization_id_never_reaches_the_job(): void
    {
        Queue::fake();

        $this->send([['name' => 'catalog_viewed', 'catalogSlug' => 'tienda', 'organization_id' => 5]], ['organization_id' => 5])->assertNoContent();

        Queue::assertPushed(ProcessTelemetryBatch::class, function (ProcessTelemetryBatch $job): bool {
            $this->assertArrayNotHasKey('organization_id', get_object_vars($job->batch));

            return true;
        });
    }

    public function test_oversized_and_malformed_batches_are_refused(): void
    {
        Queue::fake();

        $huge = str_repeat('x', TelemetryController::MAX_BODY_BYTES + 1);
        $this->call('POST', route('api.telemetry'), content: $huge, server: ['CONTENT_TYPE' => 'application/json'])->assertStatus(413);
        $this->send(array_fill(0, 51, ['name' => 'catalog_viewed']))->assertStatus(422);
        $this->postJson(route('api.telemetry'), ['visitorId' => 'no-uuid', 'sessionId' => 'sesion-abc-123', 'events' => [['name' => 'x']]])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_a_beacon_sent_as_plain_text_is_accepted(): void
    {
        Queue::fake();

        $body = (string) json_encode(['visitorId' => self::VISITOR, 'sessionId' => 'sesion-abc-123', 'events' => [['name' => 'feed_viewed']]]);
        $this->call('POST', route('api.telemetry'), content: $body, server: ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'])->assertNoContent();

        Queue::assertPushed(ProcessTelemetryBatch::class);
    }

    public function test_the_sixty_first_batch_in_a_minute_is_throttled(): void
    {
        Queue::fake();

        for ($request = 1; $request <= AppServiceProvider::TELEMETRY_BATCHES_PER_MINUTE; $request++) {
            $this->send([['name' => 'feed_viewed']])->assertNoContent();
        }

        $this->send([['name' => 'feed_viewed']])->assertStatus(429);
    }

    public function test_a_queue_failure_still_answers_no_content(): void
    {
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->andThrow(new RuntimeException('Redis caído'));

        $this->send([['name' => 'feed_viewed']])->assertNoContent();
    }

    public function test_it_needs_no_session_nor_csrf_token(): void
    {
        Queue::fake();

        $response = $this->send([['name' => 'feed_viewed']]);

        $response->assertNoContent();
        $this->assertNull($response->headers->getCookies()[0] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @param  array<string, mixed>  $extra
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function send(array $events, array $extra = []): TestResponse
    {
        return $this->postJson(route('api.telemetry'), ['visitorId' => self::VISITOR, 'sessionId' => 'sesion-abc-123', 'events' => $events, ...$extra]);
    }
}
