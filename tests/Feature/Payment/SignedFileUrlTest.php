<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Models\Receipt;
use App\Support\SignedFileUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * La URL firmada del comprobante, por audiencia (`REQ-PAY-002` RB-3).
 *
 * Va en `Feature` porque firmar necesita el router y la clave de la
 * aplicación; no toca la base: el comprobante solo aporta su clave.
 *
 * Lo que la URL abre —y lo que no— lo prueban `ReceiptFileTest` y
 * `ReceiptFileIsolationTest` por HTTP. Aquí se afirma lo que la URL **es**:
 * a qué ruta apunta y cuánto vive, leído del `expires` que viaja firmado.
 */
final class SignedFileUrlTest extends TestCase
{
    /**
     * RB-3 · 5 minutos para el cliente, por la ruta del portal.
     */
    public function test_the_customer_url_lives_five_minutes_on_the_portal_route(): void
    {
        $this->freezeSecond();

        $url = SignedFileUrl::forCustomer($this->receipt(42));

        $this->assertSame(5, SignedFileUrl::CUSTOMER_TTL_MINUTES);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $this->expiresOf($url));
        $this->assertSame('/mi-cuenta/comprobantes/42/archivo', parse_url($url, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    /**
     * RB-3 · 15 minutos para el backoffice, por la suya.
     */
    public function test_the_backoffice_url_lives_fifteen_minutes_on_the_backoffice_route(): void
    {
        $this->freezeSecond();

        $url = SignedFileUrl::forBackoffice($this->receipt(42));

        $this->assertSame(15, SignedFileUrl::BACKOFFICE_TTL_MINUTES);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), $this->expiresOf($url));
        $this->assertSame('/backoffice/comprobantes/42/archivo', parse_url($url, PHP_URL_PATH));
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
    }

    /**
     * Las dos firmas son de rutas distintas: la del cliente no abre la del
     * backoffice cambiando el camino, aunque el comprobante sea el mismo.
     */
    public function test_a_customer_signature_does_not_sign_the_backoffice_path(): void
    {
        $url = SignedFileUrl::forCustomer($this->receipt(42));
        $moved = str_replace('/mi-cuenta/comprobantes/', '/backoffice/comprobantes/', $url);

        $this->assertNotSame($url, $moved);
        $this->assertFalse(URL::hasValidSignature(Request::create($moved)));
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
