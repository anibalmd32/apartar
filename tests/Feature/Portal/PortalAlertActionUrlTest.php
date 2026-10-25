<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Money\Money;
use App\Http\Resources\PortalAlertResource;
use App\Models\Organization;
use App\Queries\PortalAlert;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalAlertActionUrlTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_debt_alerts_lead_to_the_receipt_form_and_the_pending_one_to_the_detail(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($organization, LayawayStatus::Active);
        $form = route('portal.receipts.create', ['code' => $layaway->code]);
        $detail = route('portal.layaways.show', ['code' => $layaway->code]);

        $alerts = [
            [PortalAlert::downPaymentWindow($organization, $layaway, 90), $form],
            [PortalAlert::overdue(1, [['organization' => $organization, 'amount' => new Money(3_000)]], $layaway), $form],
            [PortalAlert::receiptRejected($organization, $layaway), $form],
            [PortalAlert::dueSoon($organization, CarbonImmutable::parse('2026-10-10'), new Money(3_000), $layaway), $form],
            [PortalAlert::receiptPending(1, $layaway), $detail],
        ];

        foreach ($alerts as [$alert, $url]) {
            $resolved = $this->withinOrganization($organization, fn (): array => (new PortalAlertResource($alert))->resolve(new Request));

            $this->assertSame($url, $resolved['actionUrl'], $alert->kind->value);
        }
    }
}
