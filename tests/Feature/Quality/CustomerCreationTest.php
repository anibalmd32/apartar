<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

const CUSTOMER_CREATOR = 'app/Actions/Customer/ResolveCustomerAction.php';

/**
 * @var list<string>
 */
const CUSTOMER_RESOLVER_REFERENCES = [
    CUSTOMER_CREATOR,
    'app/Actions/Layaway/CreateLayawayAction.php',
];

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
 * @return iterable<string, string>
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
        .'apartado y el backoffice no las crea a mano; además, por fuera de la acción '
        .'se saltan la evidencia de consentimiento y la auditoría de la reutilización.',
        implode(', ', $offenders),
    ));
});

it('reconoce la creación en la propia ResolveCustomerAction', function (): void {
    $code = codeWithoutComments((string) file_get_contents(base_path(CUSTOMER_CREATOR)));

    expect(createsCustomers($code))->toBeTrue();

    expect(createsCustomers("DB::table('customers')->insert(\$row);"))->toBeTrue();
    expect(createsCustomers('Customer::query()->firstOrCreate($attributes);'))->toBeTrue();
    expect(createsCustomers('new CustomerContactData($document, $name, $phone, null);'))->toBeFalse();
    expect(createsCustomers('Customer::query()->where("id", 1)->first();'))->toBeFalse();

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
        .'transacción que el apartado; no hay endpoint de «crear cliente».',
        implode(', ', $offenders),
    ));
});
