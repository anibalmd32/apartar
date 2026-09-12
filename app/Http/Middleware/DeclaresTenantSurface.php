<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantSurface;

/**
 * Middleware que declara **de qué superficie** es la ruta que protege.
 *
 * La tabla de `REQ-ORG-002` RB-4 dice de dónde sale el tenant en cada
 * superficie y afirma que no hay ambigüedad. Sin este contrato, esa afirmación
 * quedaría repartida entre middlewares sin forma de comprobarla: la única
 * señal de que `/plataforma/*` opera sin tenant sería la **ausencia** de un
 * middleware, que es indistinguible de un olvido.
 *
 * `tenancy:audit` (`T-007`) recorre la tabla de rutas y necesita, para cada
 * una, saber qué superficie es. Lo pregunta aquí.
 */
interface DeclaresTenantSurface
{
    /**
     * Superficie que este middleware gobierna.
     */
    public static function surface(): TenantSurface;
}
