<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telemetry;

use App\Domain\Telemetry\IpHasher;
use Tests\TestCase;

final class IpHasherTest extends TestCase
{
    public function test_the_hash_is_stable_and_never_the_ip(): void
    {
        $hash = IpHasher::hash('190.12.34.56');

        $this->assertSame(64, strlen($hash));
        $this->assertSame($hash, IpHasher::hash('190.12.34.56'));
        $this->assertNotSame($hash, IpHasher::hash('190.12.34.57'));
        $this->assertStringNotContainsString('190.12', $hash);
        $this->assertNotSame(hash('sha256', '190.12.34.56'), $hash);
    }

    public function test_it_depends_on_the_application_key(): void
    {
        $before = IpHasher::hash('190.12.34.56');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->assertNotSame($before, IpHasher::hash('190.12.34.56'));
    }
}
