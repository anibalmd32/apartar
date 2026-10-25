<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telemetry;

use App\Domain\Telemetry\IngestBatchData;
use App\Domain\Telemetry\IpHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IngestBatchDataTest extends TestCase
{
    private const VISITOR = '0190F0F0-0000-7000-8000-000000000001';

    public function test_a_well_formed_batch_keeps_only_what_the_job_needs(): void
    {
        $batch = IngestBatchData::fromBody([
            'visitorId' => self::VISITOR,
            'sessionId' => 'sesion-abc-123',
            'organization_id' => 99,
            'events' => [['name' => 'catalog_viewed'], 'basura'],
        ], '10.0.0.1', str_repeat('a', 400));

        $this->assertNotNull($batch);
        $this->assertSame(strtolower(self::VISITOR), $batch->visitorId);
        $this->assertSame([['name' => 'catalog_viewed']], $batch->events);
        $this->assertSame(IpHasher::hash('10.0.0.1'), $batch->ipHash);
        $this->assertSame(255, mb_strlen((string) $batch->userAgent));
        $this->assertArrayNotHasKey('organization_id', get_object_vars($batch));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformed(): iterable
    {
        $event = ['name' => 'catalog_viewed'];

        yield 'no es un objeto' => ['texto'];
        yield 'visitante que no es UUID' => [['visitorId' => 'abc', 'sessionId' => 'sesion-abc-123', 'events' => [$event]]];
        yield 'sin sesión' => [['visitorId' => self::VISITOR, 'events' => [$event]]];
        yield 'sesión con caracteres raros' => [['visitorId' => self::VISITOR, 'sessionId' => '<script>', 'events' => [$event]]];
        yield 'sin eventos' => [['visitorId' => self::VISITOR, 'sessionId' => 'sesion-abc-123', 'events' => []]];
        yield 'eventos como mapa' => [['visitorId' => self::VISITOR, 'sessionId' => 'sesion-abc-123', 'events' => ['a' => $event]]];
        yield 'más de cincuenta' => [['visitorId' => self::VISITOR, 'sessionId' => 'sesion-abc-123', 'events' => array_fill(0, 51, $event)]];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_batch_is_refused(mixed $body): void
    {
        $this->assertNull(IngestBatchData::fromBody($body, '10.0.0.1', null));
    }
}
