<?php

declare(strict_types=1);

use App\Domain\Customer\MergeSummary;

it('dice qué movió', function (): void {
    expect((new MergeSummary(2, 3, 1, true, 5, 7))->toArray())->toBe(['layaways' => 2, 'receipts' => 3, 'login_tokens' => 1, 'account_moves' => true, 'messages' => 5, 'telemetry_events' => 7]);
});
