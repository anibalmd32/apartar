<?php

declare(strict_types=1);

use App\Domain\Tenancy\MissingTenantContextException;
use App\Domain\Tenancy\TenantAlreadySetException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;

/**
 * `TenantContext` (`REQ-ORG-002`).
 *
 * Corre sin framework (`REQ-QAX-003` RB-2): el contexto es un contenedor de
 * una organización y su lógica es la de las transiciones entre "sin tenant",
 * "con tenant" y "ejecutando en otro". Las organizaciones se hidratan a mano
 * porque aquí no hace falta la base de datos.
 */
function organizationWithId(int $id): Organization
{
    $organization = new Organization;
    $organization->forceFill(['id' => $id]);
    $organization->exists = true;

    return $organization;
}

it('empieza sin organización', function (): void {
    expect((new TenantContext)->hasOrganization())->toBeFalse();
});

it('lanza al pedir la organización si no hay ninguna', function (): void {
    expect(fn () => (new TenantContext)->organization())
        ->toThrow(MissingTenantContextException::class);
});

it('lanza al pedir el identificador si no hay ninguna', function (): void {
    expect(fn () => (new TenantContext)->organizationId())
        ->toThrow(MissingTenantContextException::class);
});

it('devuelve la organización fijada y su identificador', function (): void {
    $context = new TenantContext;
    $organization = organizationWithId(7);

    $context->setOrganization($organization);

    expect($context->hasOrganization())->toBeTrue();
    expect($context->organization())->toBe($organization);
    expect($context->organizationId())->toBe(7);
});

/**
 * SP-6: idempotente para la misma organización.
 */
it('acepta fijar dos veces la misma organización', function (): void {
    $context = new TenantContext;

    $context->setOrganization(organizationWithId(7));
    $context->setOrganization(organizationWithId(7));

    expect($context->organizationId())->toBe(7);
});

/**
 * SP-6: cambiar a otra a mitad de camino significa que algo se resolvió mal
 * antes, y que las consultas ya ejecutadas salieron con el tenant anterior.
 */
it('lanza al intentar cambiar a otra organización', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(7));

    expect(fn () => $context->setOrganization(organizationWithId(8)))
        ->toThrow(TenantAlreadySetException::class);
});

it('nombra las dos organizaciones en el mensaje del cambio rechazado', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(7));

    try {
        $context->setOrganization(organizationWithId(8));
        throw new RuntimeException('Se esperaba TenantAlreadySetException.');
    } catch (TenantAlreadySetException $exception) {
        expect($exception->getMessage())
            ->toContain('7')
            ->toContain('8')
            ->toContain('runFor');
    }
});

/**
 * SP-7: obligatorio en el `finally` de todo job. Una cola compartida procesa
 * jobs de dos organizaciones seguidas.
 */
it('olvida la organización activa', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(7));

    $context->forget();

    expect($context->hasOrganization())->toBeFalse();
});

it('permite fijar otra organización después de olvidar', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(7));
    $context->forget();

    $context->setOrganization(organizationWithId(8));

    expect($context->organizationId())->toBe(8);
});

it('ejecuta el callback con la organización pedida', function (): void {
    $context = new TenantContext;

    $seen = $context->runFor(organizationWithId(9), fn (): int => $context->organizationId());

    expect($seen)->toBe(9);
});

it('restaura la organización anterior al salir de runFor', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(7));

    $context->runFor(organizationWithId(9), fn (): int => $context->organizationId());

    expect($context->organizationId())->toBe(7);
});

it('deja el contexto vacío si no había ninguna antes de runFor', function (): void {
    $context = new TenantContext;

    $context->runFor(organizationWithId(9), fn (): bool => true);

    expect($context->hasOrganization())->toBeFalse();
});

/**
 * La parte que importa: si el callback lanza, el tenant se restaura igual. Sin
 * esto, una excepción a mitad de un lote dejaría al resto del proceso operando
 * con el tenant equivocado, que es peor que el fallo original.
 */
it('restaura el estado aunque el callback lance', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(7));

    try {
        $context->runFor(organizationWithId(9), function (): never {
            throw new RuntimeException('fallo a mitad del lote');
        });
    } catch (RuntimeException) {
        // El fallo se propaga; lo que se comprueba es el estado que deja.
    }

    expect($context->organizationId())->toBe(7);
});

it('propaga la excepción del callback sin envolverla', function (): void {
    $context = new TenantContext;

    expect(fn () => $context->runFor(organizationWithId(9), function (): never {
        throw new LogicException('original');
    }))->toThrow(LogicException::class, 'original');
});

/**
 * `runFor` anidado: cada nivel restaura el suyo.
 */
it('anida runFor restaurando nivel a nivel', function (): void {
    $context = new TenantContext;
    $context->setOrganization(organizationWithId(1));

    $context->runFor(organizationWithId(2), function () use ($context): void {
        expect($context->organizationId())->toBe(2);

        $context->runFor(organizationWithId(3), function () use ($context): void {
            expect($context->organizationId())->toBe(3);
        });

        expect($context->organizationId())->toBe(2);
    });

    expect($context->organizationId())->toBe(1);
});
