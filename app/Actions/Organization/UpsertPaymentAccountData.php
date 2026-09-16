<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Payment\PaymentMethod;

/**
 * Los datos de una cuenta de cobro, ya normalizados (`REQ-ORG-007`).
 *
 * Existe por lo mismo que `UpdateOrganizationData`: para que la acción reciba
 * datos y no un array suelto, y para que quien la construye —hoy
 * `UpsertPaymentAccountRequest`, mañana un seeder o un comando— decida de dónde
 * salen los valores sin que la acción tenga que volver a preguntárselo.
 *
 * **`bank` es opcional y no depende del método**, y es una decisión, no un
 * descuido: el banco lo pide el pago móvil venezolano, no lo pide Zelle ni el
 * efectivo, y en Binance Pay no significa nada. Exigirlo por método habría
 * añadido una regla que `REQ-ORG-007` no declara; dejarlo nulo cuando no aplica
 * es lo que la columna ya permite desde `T-008`.
 *
 * **`position` a `null` significa «al final»**, no «la primera». Quien añade
 * una cuenta nueva no está reordenando su lista: la está ampliando, y colocarla
 * arriba movería de sitio la que sus clientes llevan meses usando.
 */
final readonly class UpsertPaymentAccountData
{
    /**
     * @param  PaymentMethod  $method  cómo cobra
     * @param  string|null  $bank  banco, cuando el método lo tiene detrás
     * @param  string  $holderName  titular de la cuenta (RB-3)
     * @param  string  $holderDocument  cédula o RIF del titular (RB-3)
     * @param  string  $identifier  teléfono, cuenta, correo, Pay ID o dirección, según el método (RB-2)
     * @param  string|null  $instructions  nota corta para el cliente, ≤ 300 caracteres (RB-7)
     * @param  bool  $isActive  si se ofrece en los apartados nuevos
     * @param  int|null  $position  orden en la lista; `null` la coloca al final
     */
    public function __construct(
        public PaymentMethod $method,
        public ?string $bank,
        public string $holderName,
        public string $holderDocument,
        public string $identifier,
        public ?string $instructions,
        public bool $isActive = true,
        public ?int $position = null,
    ) {}

    /**
     * El `identifier` reducido a lo que lo hace único, para compararlo con
     * otro (SP-6).
     *
     * `0414-1234567` y `04141234567` son el mismo teléfono, y quien acaba de
     * escribir el segundo teniendo guardado el primero **sí** está duplicando
     * una cuenta. Comparar las cadenas tal cual diría que no, que es la
     * respuesta cómoda y la equivocada: el aviso de SP-6 dejaría de aparecer
     * justo en el caso en que hace falta.
     *
     * Solo se usa para comparar. Lo que se guarda y lo que ve el cliente es lo
     * que el negocio escribió, con sus guiones, porque así es como lo lee en su
     * libreta.
     *
     * **Hasta dónde llega, que conviene tenerlo escrito:** quita separadores y
     * mayúsculas, y no canoniza un móvil entre sus formas con y sin prefijo de
     * país —`+58 414 1234567` y `0414-1234567` cuentan como distintos—. Hacerlo
     * exigiría un segundo analizador de teléfonos, porque ese conocimiento vive
     * en `ValidPaymentIdentifier` y valdría para uno de los seis métodos, todo
     * a cambio de afinar **un aviso**: SP-6 permite el duplicado de todas
     * formas y lo único que se pierde es la frase que lo señala.
     */
    public function comparableIdentifier(): string
    {
        return self::comparable($this->identifier);
    }

    /**
     * La misma reducción, para el valor que ya está guardado.
     */
    public static function comparable(string $identifier): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9@.]/i', '', $identifier));
    }
}
