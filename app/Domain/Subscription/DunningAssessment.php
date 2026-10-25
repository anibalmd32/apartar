<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

use Carbon\CarbonImmutable;

final readonly class DunningAssessment
{
    public const GRACE_DAYS = 7;

    public const REVIEW_EXTENSION_HOURS = 24;

    private function __construct(
        public DunningVerdict $verdict,
        public ?CarbonImmutable $graceLastDay,
        public ?CarbonImmutable $graceEndsAt,
        public ?int $daysLeft,
        public bool $frozen,
        public bool $extended,
    ) {}

    public static function of(
        SubscriptionStatus $status,
        bool $billable,
        ?CarbonImmutable $debtSince,
        bool $underReview,
        ?CarbonImmutable $lastReviewedAt,
        CarbonImmutable $now,
        string $timezone,
        int $graceDays = self::GRACE_DAYS,
    ): self {
        if ($status === SubscriptionStatus::Cancelled) {
            return new self(DunningVerdict::Untouched, null, null, null, false, false);
        }

        if (! $billable || $debtSince === null) {
            return new self(DunningVerdict::Settled, null, null, null, false, false);
        }

        $today = $now->setTimezone($timezone)->startOfDay();
        $lastDay = CarbonImmutable::parse($debtSince->toDateString(), $timezone)->addDays($graceDays);
        $daysLeft = (int) $today->diffInDays($lastDay, false);
        $extended = $lastReviewedAt !== null && $now->lessThan($lastReviewedAt->addHours(self::REVIEW_EXTENSION_HOURS));

        $verdict = match (true) {
            $status === SubscriptionStatus::Suspended => DunningVerdict::Suspended,
            $daysLeft < 0 && ! $underReview && ! $extended => DunningVerdict::Suspended,
            default => DunningVerdict::PastDue,
        };

        return new self($verdict, $lastDay, $lastDay->endOfDay(), $daysLeft, $underReview, $extended);
    }

    public static function debtStartOf(CarbonImmutable $dueDate, CarbonImmutable $issuedAt, string $timezone): CarbonImmutable
    {
        $due = CarbonImmutable::parse($dueDate->toDateString(), $timezone);
        $issued = CarbonImmutable::parse($issuedAt->setTimezone($timezone)->toDateString(), $timezone);

        return $issued->greaterThan($due) ? $issued : $due;
    }
}
