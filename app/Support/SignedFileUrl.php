<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Receipt;
use Illuminate\Support\Facades\URL;

/**
 * La URL firmada y caducable del archivo de un comprobante (`REQ-PAY-002`
 * RB-2, RB-3).
 *
 * ## Una por audiencia, y no `forReceipt($r, $ttl)`
 *
 * [22] escribe `forReceipt(Receipt $r, int $ttlMinutes)` y una sola ruta. Aquí
 * son dos métodos sobre dos rutas, y el TTL deja de ser un argumento:
 *
 *  - **La ruta**, porque una ruta declara una sola superficie de tenant
 *    (`CrossTenantAccessTest`) y el comprobante lo abren dos: el cliente desde
 *    el portal (tenant de su ficha) y el equipo desde el backoffice (tenant de
 *    su usuario). Sin tenant, el binding de `Receipt` lanza en vez de dar el
 *    `404` de `REQ-ORG-002` RB-6.
 *  - **El TTL**, porque RB-3 lo fija por audiencia —5 minutos el cliente, 15 el
 *    backoffice— y un entero suelto en cada llamada es la forma de que alguien
 *    pase 60 «para que no caduque mientras revisa». Con el método, quien
 *    genera la URL dice para quién es y la regla decide cuánto dura.
 *
 * La firma es la de Laravel (`URL::temporarySignedRoute`, validada por el
 * middleware `signed`): cubre la ruta entera y `expires`, así que cambiar el
 * comprobante de la URL o alargar su caducidad la invalida. **La firma sola no
 * autoriza**: es la primera de las tres comprobaciones de RB-2, y las otras
 * dos —policy y tenant— están en `ReceiptFileController`.
 */
final class SignedFileUrl
{
    /** RB-3 · lo que vive la URL que ve el cliente en el portal. */
    public const CUSTOMER_TTL_MINUTES = 5;

    /** RB-3 · lo que vive la del backoffice: revisar un comprobante lleva más que mirarlo. */
    public const BACKOFFICE_TTL_MINUTES = 15;

    /** La ruta del portal (`routes/portal.php`). */
    public const CUSTOMER_ROUTE = 'portal.receipts.file';

    /**
     * La del backoffice (`routes/backoffice.php`). Sin prefijo `backoffice.`
     * porque el grupo no lo pone: sus rutas se llaman `layaways.show`,
     * `team.index`…
     */
    public const BACKOFFICE_ROUTE = 'receipts.file';

    /**
     * Para el cliente titular, abierta desde su sesión del portal.
     */
    public static function forCustomer(Receipt $receipt): string
    {
        return self::sign(self::CUSTOMER_ROUTE, $receipt, self::CUSTOMER_TTL_MINUTES);
    }

    /**
     * Para el equipo del negocio, abierta desde el backoffice.
     */
    public static function forBackoffice(Receipt $receipt): string
    {
        return self::sign(self::BACKOFFICE_ROUTE, $receipt, self::BACKOFFICE_TTL_MINUTES);
    }

    private static function sign(string $route, Receipt $receipt, int $minutes): string
    {
        return URL::temporarySignedRoute($route, now()->addMinutes($minutes), ['receipt' => $receipt->getKey()]);
    }
}
