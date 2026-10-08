<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que encontró una pasada de `layaways:reconcile` (`REQ-PAY-008` RB-4).
 *
 * Cuenta lo revisado y guarda cada descuadre —de un apartado o del faltante
 * de un producto— con lo que se hizo con él:
 *
 *  - `reported`: se informa y no se toca (sin `--fix`, SP-6);
 *  - `fixed`: cuadra al terminar —corregido y auditado, o ya lo estaba
 *    cuando se tomó la fila bloqueada—;
 *  - `skipped`: la fila estaba bloqueada por otra operación y queda para la
 *    pasada siguiente (SP-5);
 *  - `unresolved`: corregido lo que se podía, pero los pagos y las cuotas
 *    siguen sin contar lo mismo, sigue pagado de más, o es un terminal con
 *    cuotas descuadradas que no se reconstruyen —lo mira una persona—;
 *  - `failed`: la corrección lanzó; está en el log de errores, y el resto de
 *    la pasada siguió.
 *
 * El resumen de la última pasada **de informe sobre toda la plataforma** queda
 * guardado para la pantalla de Salud (`REQ-PLT-006` RB-2, `T-086`):
 * `last()`. Una pasada con `--fix` o acotada a una organización no lo
 * sobrescribe: contaría como descuadres lo que ella misma acaba de corregir, o
 * hablaría de un solo negocio.
 */
final class ReconciliationReport
{
    public const CACHE_KEY = 'layaways:reconcile:last';

    /** Lo que se guarda de cada descuadre en el log: el resto, en el resumen. */
    private const LOGGED_ENTRIES = 50;

    public int $layaways = 0;

    public int $products = 0;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    /**
     * @param  array<string, mixed>  $entry  `type`, `organization_id`, `id`, el detalle y `outcome`
     */
    public function add(array $entry): void
    {
        $this->entries[] = $entry;
    }

    public function merge(self $other): void
    {
        $this->layaways += $other->layaways;
        $this->products += $other->products;
        $this->entries = [...$this->entries, ...$other->entries];
    }

    /** Cuántos descuadres encontró, se corrigieran o no. */
    public function discrepancies(): int
    {
        return count($this->entries);
    }

    /** De ellos, cuántos son de saldos de apartados: la señal de `REQ-PLT-006` RB-2. */
    public function balanceDiscrepancies(): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['type'] === 'layaway'));
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->entries, fn (array $entry): bool => $entry['outcome'] === $outcome));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Para el log: las cifras y los primeros descuadres.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'layaways' => $this->layaways,
            'products' => $this->products,
            'discrepancies' => $this->discrepancies(),
            'fixed' => $this->count('fixed'),
            'skipped' => $this->count('skipped'),
            'unresolved' => $this->count('unresolved'),
            'entries' => array_slice($this->entries, 0, self::LOGGED_ENTRIES),
        ];
    }

    /**
     * Guarda el resumen de esta pasada como la última.
     *
     * En la caché y sin caducidad: es una señal, no un registro —el registro
     * es el log y, de lo corregido, la auditoría—. Si la caché se vacía, la
     * pantalla de Salud dirá que no hay pasada conocida hasta la siguiente.
     */
    public function remember(CarbonImmutable $ranAt): void
    {
        Cache::forever(self::CACHE_KEY, [
            'discrepancies' => $this->balanceDiscrepancies(),
            'stock' => $this->discrepancies() - $this->balanceDiscrepancies(),
            'unresolved' => $this->count('unresolved'),
            'lastRunAt' => $ranAt->toIso8601String(),
        ]);
    }

    /**
     * La última pasada: los saldos descuadrados, los faltantes de existencias
     * descuadrados, los que ni `--fix` sabría corregir y cuándo; o `null` si
     * no hay ninguna conocida.
     *
     * @return array{discrepancies: int, stock: int, unresolved: int, lastRunAt: string}|null
     */
    public static function last(): ?array
    {
        $last = Cache::get(self::CACHE_KEY);

        if (! is_array($last) || ! isset($last['discrepancies'], $last['stock'], $last['unresolved'], $last['lastRunAt'])) {
            return null;
        }

        return [
            'discrepancies' => (int) $last['discrepancies'],
            'stock' => (int) $last['stock'],
            'unresolved' => (int) $last['unresolved'],
            'lastRunAt' => (string) $last['lastRunAt'],
        ];
    }
}
