<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class ServerTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_after_commit_and_never_on_rollback(): void
    {
        try {
            DB::transaction(function (): void {
                ServerTelemetry::record(TelemetryEventName::AccountCreated, payload: ['provider' => 'google'], accountId: 1);

                throw new RuntimeException('se deshace');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('telemetry_events')->count());

        DB::transaction(fn () => ServerTelemetry::record(TelemetryEventName::AccountCreated, payload: ['provider' => 'google', 'email' => 'x@y.test'], accountId: 1));

        $row = DB::table('telemetry_events')->sole();

        $this->assertSame('account_created', $row->event_name);
        $this->assertSame(['provider' => 'google'], json_decode($row->payload, true));
        $this->assertSame(ServerTelemetry::SESSION, $row->session_id);
        $this->assertSame(64, strlen($row->ip_hash));
        $this->assertNull($row->subject_type);
    }

    public function test_a_failure_never_reaches_the_caller(): void
    {
        ServerTelemetry::record(TelemetryEventName::AccountCreated, payload: ['provider' => "\xB1\x31"], accountId: 1);

        $this->assertSame(0, DB::table('telemetry_events')->count());
    }
}
