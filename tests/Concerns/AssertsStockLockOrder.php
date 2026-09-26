<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Afirma el orden canónico de bloqueo de `REQ-LAY-003` RB-8 sobre el registro
 * de consultas: **el producto antes que cualquier otra fila del tenant**.
 *
 * ## Por qué sobre las consultas y no provocando el abrazo
 *
 * Un *deadlock* solo existe entre dos transacciones confirmadas que esperan la
 * una a la otra, y en un solo proceso de PHP no se puede tener a dos esperando
 * a la vez. Un orden invertido, entonces, **pasa toda la suite** y se ve en
 * producción. Lo que sí es determinista es la secuencia de sentencias: si la
 * primera que bloquea o escribe una fila del tenant no es el `FOR UPDATE` de
 * `products`, el orden está roto, y esto lo dice con la consulta culpable.
 *
 * Se usa desde `StockLockOrderTest` y está aquí para que `T-045` lo aplique a
 * `CreateLayawayAction`, que es la acción que junta los cuatro bloqueos.
 */
trait AssertsStockLockOrder
{
    /**
     * Ejecuta `$operation` y afirma que la primera fila del tenant que bloquea
     * o escribe es la del producto.
     */
    protected function assertLocksTheProductFirst(Closure $operation, string $context = ''): void
    {
        $violation = $this->stockLockOrderViolation($this->statementsOf($operation));

        $this->assertNull($violation, trim($context.' · El orden canónico es producto primero (REQ-LAY-003 RB-8), y '
            .'esta sentencia bloqueó o escribió antes: '.$violation));
    }

    /**
     * La sentencia que rompe el orden, o `null` si el producto va primero.
     *
     * Cuentan como «tomar una fila» los `FOR UPDATE`, los `UPDATE`, los
     * `INSERT` y los `DELETE`: un `UPDATE` bloquea la fila igual que un
     * `SELECT … FOR UPDATE`, y es la forma en que el orden se invierte sin
     * que nadie escriba un bloqueo explícito. Las lecturas simples no cuentan:
     * no toman nada.
     *
     * @param  list<string>  $statements
     */
    protected function stockLockOrderViolation(array $statements): ?string
    {
        foreach ($statements as $sql) {
            $normalised = strtolower((string) preg_replace('/\s+/', ' ', $sql));

            $takesRows = str_contains($normalised, ' for update')
                || preg_match('/^(update|insert|delete)\b/', $normalised) === 1;

            if (! $takesRows || $this->touchesOnlyPlatformTables($normalised)) {
                continue;
            }

            $locksProduct = str_contains($normalised, ' for update')
                && preg_match('/^select .* from `products`/', $normalised) === 1;

            return $locksProduct ? null : $sql;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function statementsOf(Closure $operation): array
    {
        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $operation();

        return $statements;
    }

    /**
     * Lo que se escribe fuera del tenant —los *savepoints* no pasan por aquí,
     * pero la caché y los trabajos encolados sí— no participa en el orden.
     */
    private function touchesOnlyPlatformTables(string $sql): bool
    {
        return preg_match('/^(insert into|update|delete from) `(cache|cache_locks|jobs|sessions)`/', $sql) === 1;
    }
}
