<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Payment;
use App\Models\Receipt;
use App\Support\SignedFileUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class SignedFileUrlTest extends TestCase
{
    public function test_the_customer_url_lives_five_minutes_on_the_portal_route(): void
    {
        $this->freezeSecond();

        $url = SignedFileUrl::forCustomer($this->receipt(42));

        $this->assertSame(5, SignedFileUrl::CUSTOMER_TTL_MINUTES);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $this->expiresOf($url));
        $this->assertSame('/mi-cuenta/comprobantes/42/archivo', parse_url($url, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    public function test_the_backoffice_url_lives_fifteen_minutes_on_the_backoffice_route(): void
    {
        $this->freezeSecond();

        $url = SignedFileUrl::forBackoffice($this->receipt(42));

        $this->assertSame(15, SignedFileUrl::BACKOFFICE_TTL_MINUTES);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), $this->expiresOf($url));
        $this->assertSame('/backoffice/comprobantes/42/archivo', parse_url($url, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    public function test_a_customer_signature_does_not_sign_the_backoffice_path(): void
    {
        $url = SignedFileUrl::forCustomer($this->receipt(42));
        $moved = str_replace('/mi-cuenta/comprobantes/', '/backoffice/comprobantes/', $url);

        $this->assertNotSame($url, $moved);
        $this->assertFalse(URL::hasValidSignature(Request::create($moved)));
    }

    public function test_the_payment_urls_live_on_their_own_routes_with_the_same_ttls(): void
    {
        $this->freezeSecond();
        $payment = (new Payment)->forceFill(['id' => 42, 'organization_id' => 7]);

        $customer = SignedFileUrl::forCustomerPayment($payment);
        $backoffice = SignedFileUrl::forBackofficePayment($payment);

        $this->assertSame('/mi-cuenta/pagos/42/archivo', parse_url($customer, PHP_URL_PATH));
        $this->assertSame(now()->addMinutes(SignedFileUrl::CUSTOMER_TTL_MINUTES)->getTimestamp(), $this->expiresOf($customer));
        $this->assertSame('/backoffice/pagos/42/archivo', parse_url($backoffice, PHP_URL_PATH));
        $this->assertSame(now()->addMinutes(SignedFileUrl::BACKOFFICE_TTL_MINUTES)->getTimestamp(), $this->expiresOf($backoffice));
        $this->assertTrue(URL::hasValidSignature(Request::create($backoffice)));
        $this->assertFalse(URL::hasValidSignature(Request::create(str_replace('/pagos/42/', '/comprobantes/42/', $backoffice))));
    }

    private function receipt(int $id): Receipt
    {
        return (new Receipt)->forceFill(['id' => $id]);
    }

    private function expiresOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('expires', $query);

        return (int) $query['expires'];
    }
}
