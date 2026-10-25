<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telemetry;

use App\Domain\Telemetry\TelemetryEventName;
use PHPUnit\Framework\TestCase;

final class TelemetryEventNameTest extends TestCase
{
    public function test_there_are_exactly_fifteen_events(): void
    {
        $this->assertCount(15, TelemetryEventName::cases());
    }

    public function test_the_essential_events_are_the_five_deliberate_actions(): void
    {
        $essential = array_values(array_map(
            fn (TelemetryEventName $name): string => $name->value,
            array_filter(TelemetryEventName::cases(), fn (TelemetryEventName $name): bool => $name->isEssential()),
        ));

        $this->assertSame(['layaway_created', 'receipt_uploaded', 'account_created', 'catalog_subscribed', 'catalog_unsubscribed'], $essential);
    }

    public function test_no_payload_key_can_carry_personal_data(): void
    {
        foreach (TelemetryEventName::cases() as $name) {
            foreach (array_keys($name->payloadRules()) as $key) {
                $this->assertNotContains($key, ['email', 'phone', 'name', 'document', 'document_number', 'ip', 'amount', 'reference'], $name->value);
            }
        }
    }

    public function test_the_feed_and_account_events_need_no_catalog(): void
    {
        foreach ([TelemetryEventName::FeedViewed, TelemetryEventName::FeedProductClicked, TelemetryEventName::AccountCreated, TelemetryEventName::CurrencyToggled] as $name) {
            $this->assertFalse($name->requiresCatalog(), $name->value);
        }

        foreach ([TelemetryEventName::CatalogViewed, TelemetryEventName::ProductViewed, TelemetryEventName::SearchPerformed, TelemetryEventName::DwellTimeRecorded] as $name) {
            $this->assertTrue($name->requiresCatalog(), $name->value);
        }
    }

    public function test_subjects_are_catalogs_products_or_layaways(): void
    {
        $this->assertSame('catalog', TelemetryEventName::CatalogViewed->subjectType());
        $this->assertSame('product', TelemetryEventName::FeedProductClicked->subjectType());
        $this->assertSame('layaway', TelemetryEventName::ReceiptUploaded->subjectType());
        $this->assertNull(TelemetryEventName::SearchPerformed->subjectType());
    }
}
