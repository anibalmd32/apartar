<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

trait AssertsStockLockOrder
{
    protected function assertLocksTheProductFirst(Closure $operation, string $context = ''): void
    {
        $violation = $this->stockLockOrderViolation($this->statementsOf($operation));

        $this->assertNull($violation, trim($context.' · El orden canónico es producto primero, y '
            .'esta sentencia bloqueó o escribió antes: '.$violation));
    }

    /**
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

    private function touchesOnlyPlatformTables(string $sql): bool
    {
        return preg_match('/^(insert into|update|delete from) `(cache|cache_locks|jobs|sessions)`/', $sql) === 1;
    }
}
