<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `REQ-IDN-001` RB-6 y RB-8: **una ficha solo nace de un apartado.**
 *
 * No hay endpoint de «crear cliente» y el backoffice no da de alta fichas a
 * mano, para que no exista un cliente sin operación. Dicho en código: la
 * única clase que crea un `Customer` es `ResolveCustomerAction`, y la única
 * que la llama es `CreateLayawayAction` (`T-045`). Esto recorre `app/` y lo
 * comprueba, y es también lo que hace cierto el «no existe ruta ni controlador
 * de cliente» de `T-043`: un controlador que creara fichas tendría que pasar
 * por una de estas dos puertas, y las dos están vigiladas.
 *
 * Y cubre de paso la evidencia de consentimiento: la guarda del modelo impide
 * guardar un `Customer` sin ella, pero un `insert()` del *query builder* no
 * dispara eventos de Eloquent. Es la misma pareja que `LayawayStatusWritesTest`
 * con el estado del apartado.
 *
 * Los directorios de clientes de `T-065` y el portal de `T-049` **leen**
 * fichas y no chocan con esto. Si una tarea futura necesita crear una ficha
 * por otra vía, lo que tiene que cambiar es RB-8, no esta lista.
 */
const CUSTOMER_CREATOR = 'app/Actions/Customer/ResolveCustomerAction.php';

/**
 * Quién puede usar la acción: ella misma y su único llamador.
 *
 * @var list<string>
 */
const CUSTOMER_RESOLVER_REFERENCES = [
    CUSTOMER_CREATOR,
    'app/Actions/Layaway/CreateLayawayAction.php',
];

/**
 * El código sin comentarios —la documentación nombra estas formas para
 * explicarlas—. Con `$withStrings` conserva los literales, que es donde viven
 * la tabla y el SQL; sin ellos, lo que queda son las referencias de código, y
 * no los mensajes de error ni el emisor declarado como cadena en `AuditEvent`.
 */
function codeWithoutComments(string $code, bool $withStrings = true): string
{
    $skipped = $withStrings
        ? [T_COMMENT, T_DOC_COMMENT]
        : [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

    $stripped = '';

    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], $skipped, true)) {
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

function createsCustomers(string $code): bool
{
    $methods = '(create|forceCreate|firstOrCreate|updateOrCreate|createOrFirst|firstOrNew|insert|insertGetId|insertOrIgnore|upsert|updateOrInsert)';

    return preg_match('/\bnew\s+(\\\\?App\\\\Models\\\\)?Customer\b(?!\w)/', $code) === 1
        || preg_match('/\bCustomer::(query\(\)\s*->\s*)?'.$methods.'\s*\(/', $code) === 1
        || preg_match('/->\s*customers\(\)\s*->\s*'.$methods.'\s*\(/', $code) === 1
        || preg_match('/table\(\s*[\'"]customers[\'"]\s*\)\s*->\s*'.$methods.'\s*\(/', $code) === 1
        || preg_match('/insert\s+into\s+`?customers`?\b/i', $code) === 1;
}

/**
 * @return iterable<string, string> ruta relativa => código sin comentarios
 */
function applicationCode(bool $withStrings = true): iterable
{
    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        yield $relative => codeWithoutComments((string) file_get_contents((string) $file->getRealPath()), $withStrings);
    }
}

it('solo crea fichas de cliente ResolveCustomerAction', function (): void {
    $offenders = [];

    foreach (applicationCode() as $path => $code) {
        if ($path !== CUSTOMER_CREATOR && createsCustomers($code)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], sprintf(
        'Estos archivos crean fichas de cliente por fuera de ResolveCustomerAction: %s. Una ficha nace del '
        .'apartado (REQ-IDN-001 RB-6) y el backoffice no las crea a mano (RB-8); además, por fuera de la acción '
        .'se saltan la evidencia de consentimiento y la auditoría de la reutilización.',
        implode(', ', $offenders),
    ));
});

/**
 * La otra mitad: el escaneo **no puede estar ciego**. Si el patrón dejara de
 * reconocer la creación, el test de arriba pasaría en verde sobre nada.
 */
it('reconoce la creación en la propia ResolveCustomerAction', function (): void {
    $code = codeWithoutComments((string) file_get_contents(base_path(CUSTOMER_CREATOR)));

    expect(createsCustomers($code))->toBeTrue();

    expect(createsCustomers("DB::table('customers')->insert(\$row);"))->toBeTrue();
    expect(createsCustomers('Customer::query()->firstOrCreate($attributes);'))->toBeTrue();
    expect(createsCustomers('new CustomerContactData($document, $name, $phone, null);'))->toBeFalse();
    expect(createsCustomers('Customer::query()->where("id", 1)->first();'))->toBeFalse();

    // Y la búsqueda de llamadores ve el código y no las cadenas: la clase se
    // encuentra en su propio archivo, y el emisor de `AuditEvent`, que es una
    // cadena, no cuenta como llamada.
    expect(str_contains(codeWithoutComments((string) file_get_contents(base_path(CUSTOMER_CREATOR)), withStrings: false), 'ResolveCustomerAction'))->toBeTrue();
    expect(str_contains(codeWithoutComments((string) file_get_contents(base_path('app/Domain/Auditing/AuditEvent.php')), withStrings: false), 'ResolveCustomerAction'))->toBeFalse();
});

it('solo llama a ResolveCustomerAction la creación del apartado', function (): void {
    $offenders = [];

    foreach (applicationCode(withStrings: false) as $path => $code) {
        if (str_contains($code, 'ResolveCustomerAction') && ! in_array($path, CUSTOMER_RESOLVER_REFERENCES, true)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], sprintf(
        'Estos archivos usan ResolveCustomerAction y no son CreateLayawayAction: %s. La ficha se crea en la misma '
        .'transacción que el apartado (REQ-IDN-001 RB-6); no hay endpoint de «crear cliente».',
        implode(', ', $offenders),
    ));
});
