<?php

declare(strict_types=1);

use App\Actions\Organization\UpsertPaymentAccountData;
use App\Domain\Payment\PaymentMethod;

/**
 * Los datos de una cuenta de cobro, ya normalizados (`REQ-ORG-007`).
 *
 * Sin base de datos: lo único que esta clase decide es **qué hace iguales a dos
 * identificadores**, que es de lo que depende el aviso de SP-6.
 */
function accountData(string $identifier, ?int $position = null): UpsertPaymentAccountData
{
    return new UpsertPaymentAccountData(
        method: PaymentMethod::PagoMovil,
        bank: 'Banesco',
        holderName: 'Carlos Rojas',
        holderDocument: 'V-12345678',
        identifier: $identifier,
        instructions: null,
        position: $position,
    );
}

/**
 * **`0414-1234567` y `04141234567` son el mismo teléfono.**
 *
 * Comparar las cadenas tal cual diría que no, que es la respuesta cómoda y la
 * equivocada: el aviso de SP-6 dejaría de aparecer justo en el caso en que
 * hace falta, el de quien vuelve a escribir la misma cuenta de otra manera.
 */
it('reduce el identificador a lo que lo hace único', function (): void {
    expect(accountData('0414-1234567')->comparableIdentifier())
        ->toBe(accountData('04141234567')->comparableIdentifier())
        ->and(accountData('0414 1234567')->comparableIdentifier())
        ->toBe('04141234567');
});

/**
 * **Y hasta dónde llega, que es lo que conviene tener escrito.**
 *
 * `+58 414 1234567` es el mismo teléfono que `0414-1234567` y aquí cuentan como
 * distintos. Es deliberado: canonizar un móvil entre sus formas con y sin
 * prefijo de país es conocimiento de `ValidPaymentIdentifier`, y copiarlo aquí
 * sería un segundo analizador de teléfonos —que además valdría solo para un
 * método de seis— a cambio de afinar **un aviso**. SP-6 permite el duplicado de
 * todas formas; lo único que se pierde es la frase que lo señala.
 */
it('no canoniza un móvil entre sus formas con y sin prefijo de país', function (): void {
    expect(accountData('+58 414 1234567')->comparableIdentifier())->toBe('584141234567');
});

/**
 * Los correos se comparan sin distinguir mayúsculas: `Pagos@X.com` y
 * `pagos@x.com` son el mismo buzón, y el arroba y el punto sobreviven a la
 * reducción porque son lo que distingue un correo de otro.
 */
it('compara los correos sin distinguir mayúsculas', function (): void {
    expect(UpsertPaymentAccountData::comparable('Pagos@ElTornillo.com'))->toBe('pagos@eltornillo.com');
});

/**
 * Dos identificadores distintos siguen siendo distintos: la reducción quita
 * ruido, no información.
 */
it('no confunde dos cuentas distintas', function (): void {
    expect(accountData('0414-1234567')->comparableIdentifier())
        ->not->toBe(accountData('0424-7654321')->comparableIdentifier());
});

/**
 * `position` a `null` significa «al final», y por eso viaja como `null` y no
 * como un cero: quien añade una cuenta no está reordenando su lista, la está
 * ampliando, y colocarla arriba movería de sitio la que sus clientes llevan
 * meses usando.
 */
it('deja la posición sin decidir cuando no se pide una', function (): void {
    expect(accountData('0414-1234567')->position)->toBeNull()
        ->and(accountData('0414-1234567', position: 3)->position)->toBe(3);
});

/**
 * Y lo que se guarda es lo que el negocio escribió: la reducción es solo para
 * comparar. El catálogo tiene que enseñar `0414-1234567` y no
 * `04141234567`, porque así es como el dueño lo lee en su libreta.
 */
it('no toca el identificador que se guarda', function (): void {
    expect(accountData('0414-1234567')->identifier)->toBe('0414-1234567');
});
