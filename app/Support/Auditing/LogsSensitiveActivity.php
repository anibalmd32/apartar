<?php

declare(strict_types=1);

namespace App\Support\Auditing;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Auditing\AuditProperties;
use App\Domain\Auditing\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Throwable;

trait LogsSensitiveActivity
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function audit(
        AuditEvent $event,
        ?Model $subject = null,
        ?Model $actor = null,
        array $before = [],
        array $after = [],
        ?string $process = null,
    ): void {
        $description = $this->auditDescription($event, $subject, $actor, $process);

        try {
            $properties = AuditProperties::between($before, $after)->toArray();
        } catch (Throwable $exception) {
            report($exception);

            $properties = [];
        }

        try {
            $this->auditTrail()->record($event, $description, $subject, $actor, $properties);
        } catch (Throwable $exception) {
            report($exception);

            $this->recordMinimalAuditEntry($event, $description, $subject, $actor);
        }
    }

    private function recordMinimalAuditEntry(AuditEvent $event, string $description, ?Model $subject, ?Model $actor): void
    {
        try {
            $this->auditTrail()->record($event, $description, $subject, $actor, []);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function auditDescription(AuditEvent $event, ?Model $subject, ?Model $actor, ?string $process): string
    {
        $description = $event->label();

        $label = $this->auditSubjectLabel($subject);

        if ($label !== null) {
            $description .= ': '.$label;
        }

        if ($actor === null) {
            $description .= ' · '.($process ?? 'proceso del sistema');
        }

        return $description;
    }

    private function auditSubjectLabel(?Model $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }

        foreach (['name', 'full_name', 'title', 'code', 'email', 'identifier'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $key = $subject->getKey();

        return $key === null ? null : class_basename($subject).' #'.$key;
    }

    private function auditTrail(): AuditTrail
    {
        return app(AuditTrail::class);
    }
}
