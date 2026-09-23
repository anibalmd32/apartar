<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * La comprobación de contraseñas filtradas, sin salir a Internet
 * (`REQ-QAX-003` RB-9).
 *
 * ## Por qué existe
 *
 * `Password::uncompromised()` consulta el servicio de *Have I Been Pwned*, y
 * los tests que lo ejercitaban lo consultaban **de verdad**: cada registro,
 * cada cambio forzado de contraseña y cada invitación aceptada de la suite
 * salía a `api.pwnedpasswords.com`. Pasaban, así que nadie lo notó — hasta que
 * `T-003b` puso la suite a correr en paralelo y veinte procesos golpearon ese
 * servicio a la vez. Entonces se vieron las dos formas en que aquello podía
 * romperse: un `Could not resolve host` y un *timeout* de diez segundos,
 * ninguno de los dos culpa del código.
 *
 * Y había una tercera, peor, que no se veía: cuando la petición falla, el
 * verificador de Laravel da la contraseña por **no filtrada**. Es decir, el
 * test que comprueba que una contraseña filtrada se rechaza pasaba a verde
 * siempre que la red fallara en el sentido contrario.
 *
 * Lo que la paralelización destapó no fue un test frágil sino **una regla
 * escrita que no se estaba cumpliendo**: RB-9 dice que ningún test llega a la
 * red real, y es por lo obvio —CI se cae cuando se cae un tercero— y por lo
 * menos obvio: un test que depende de un servicio ajeno afirma algo distinto
 * cada día sin que su código cambie.
 *
 * ## Cómo se falsea sin dejar de probar la regla
 *
 * El protocolo del servicio es *k-anonymity*: se manda el **prefijo** de cinco
 * caracteres del SHA-1 de la contraseña y la respuesta trae todos los sufijos
 * conocidos con ese prefijo, con cuántas veces aparece cada uno. Falsear eso
 * **no debilita el test**: la regla sigue calculando el hash, sigue partiéndolo
 * y sigue buscando su sufijo en la respuesta. Lo único que cambia es quién la
 * sirve.
 *
 * El hash se calcula aquí a partir de la contraseña y no se escribe a mano:
 * una constante copiada de una ejecución sería la forma de que el test dejara
 * de probar nada el día que alguien cambie la contraseña del caso.
 *
 * ## Y el stub se registra una sola vez, al arrancar cada test
 *
 * Lo hace `TestCase`, así que **ningún** test puede llegar al servicio por
 * descuido. Los casos que necesitan una contraseña filtrada no vuelven a
 * falsear nada: apuntan el prefijo en `$pwnedRanges` y el stub —que lee ese
 * mapa en el momento de la petición, no al registrarse— responde en
 * consecuencia. Registrar un segundo `Http::fake()` no habría servido: los
 * stubs se acumulan y gana el primero que casa, que sería siempre este.
 */
trait FakesPwnedPasswords
{
    /**
     * Prefijo de cinco caracteres → cuerpo que devuelve el servicio.
     *
     * Vacío significa «ninguna contraseña con ese prefijo está filtrada», que
     * es la respuesta por defecto y la que deja pasar las contraseñas buenas
     * del resto de la suite.
     *
     * @var array<string, string>
     */
    private array $pwnedRanges = [];

    /**
     * Deja el servicio de filtraciones servido desde aquí.
     */
    protected function fakeUncompromisedPasswordService(): void
    {
        Http::fake([
            // Sin tipo de retorno declarado: `Http::response()` devuelve la
            // promesa que el cliente espera, no una `Response`.
            'api.pwnedpasswords.com/range/*' => function (Request $request) {
                $prefix = strtoupper(basename(parse_url($request->url(), PHP_URL_PATH) ?: ''));

                return Http::response($this->pwnedRanges[$prefix] ?? '');
            },
        ]);
    }

    /**
     * Hace que esa contraseña aparezca como filtrada.
     *
     * @param  int  $appearances  cuántas veces «aparece»; cualquier número mayor que cero la rechaza
     */
    protected function fakePwnedPassword(string $password, int $appearances = 42): void
    {
        [$prefix, $suffix] = $this->pwnedHashParts($password);

        $this->pwnedRanges[$prefix] = $suffix.':'.$appearances;
    }

    /**
     * Comprueba que se consultó el prefijo de esa contraseña.
     *
     * Es la mitad que impide que el falseo se coma la regla: si alguien quitara
     * `uncompromised()` del formulario, el caso podría seguir en verde por otro
     * motivo, y esto lo señala.
     */
    protected function assertPwnedServiceWasAsked(string $password): void
    {
        [$prefix] = $this->pwnedHashParts($password);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/range/'.$prefix));
    }

    /**
     * El prefijo de cinco y el resto del SHA-1, en mayúsculas, que es como los
     * compara el verificador.
     *
     * @return array{0: string, 1: string}
     */
    private function pwnedHashParts(string $password): array
    {
        $hash = strtoupper(sha1($password));

        return [substr($hash, 0, 5), substr($hash, 5)];
    }
}
