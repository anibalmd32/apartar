<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\PushReportHandler;
use NotificationChannels\WebPush\ReportHandlerInterface;
use PHPUnit\Framework\TestCase;

final class PushReportHandlerTest extends TestCase
{
    public function test_it_is_the_packages_report_handler(): void
    {
        $this->assertInstanceOf(ReportHandlerInterface::class, new PushReportHandler);
    }
}
