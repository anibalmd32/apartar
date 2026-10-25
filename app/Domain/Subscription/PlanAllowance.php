<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum PlanAllowance: string
{
    case ChatHistoryDays = 'chat_history_days';

    case TelemetryRetentionDays = 'telemetry_retention_days';
}
