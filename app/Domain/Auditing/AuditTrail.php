<?php

declare(strict_types=1);

namespace App\Domain\Auditing;

use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;

class AuditTrail
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        AuditEvent $event,
        string $description,
        ?Model $subject,
        ?Model $causer,
        array $properties,
    ): void {
        AuditEntry::query()->create([
            'log_name' => AuditEntry::LOG_NAME,
            'event' => $event->value,
            'description' => $description,

            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),

            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),

            'properties' => $properties,
        ]);
    }
}
