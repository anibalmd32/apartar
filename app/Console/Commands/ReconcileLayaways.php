<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Layaway\ReconciliationReport;
use App\Jobs\ReconcileLayaways as ReconcileLayawaysJob;
use App\Models\Organization;
use Illuminate\Console\Command;

final class ReconcileLayaways extends Command
{
    protected $signature = 'layaways:reconcile
        {--fix : Corrige lo derivable y lo audita; sin esto, solo informa}
        {--organization= : Acota a una organización, por su id}';

    protected $description = 'Compara los saldos y faltantes materializados con lo que dicen cuotas, pagos y apartados';

    public function handle(): int
    {
        $organization = $this->option('organization');
        $organizationId = null;

        if ($organization !== null) {
            $organizationId = ctype_digit($organization) ? (int) $organization : 0;

            if (! Organization::query()->whereKey($organizationId)->exists()) {
                $this->error(sprintf('No existe la organización «%s».', (string) $organization));

                return self::FAILURE;
            }
        }

        $fix = (bool) $this->option('fix');
        $report = app()->call([new ReconcileLayawaysJob($fix, $organizationId), 'handle']);

        if (! $report instanceof ReconciliationReport) {
            $this->error('Hay otra pasada de la reconciliación en curso: vuelve a intentarlo cuando termine.');

            return self::FAILURE;
        }

        $this->render($report, $fix);

        $pending = $report->discrepancies() - $report->count('fixed');

        return $pending === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function render(ReconciliationReport $report, bool $fix): void
    {
        $this->info(sprintf(
            '%d apartados y %d productos con faltante revisados; %d descuadres%s.',
            $report->layaways,
            $report->products,
            $report->discrepancies(),
            $fix ? sprintf(' (%d corregidos, %d bloqueados, %d por revisar, %d fallidos)', $report->count('fixed'), $report->count('skipped'), $report->count('unresolved'), $report->count('failed')) : '',
        ));

        if ($report->discrepancies() === 0) {
            return;
        }

        $this->table(['Tipo', 'Organización', 'Registro', 'Qué no cuadra', 'Resultado'], array_map(fn (array $entry): array => [
            $entry['type'] === 'product' ? 'producto' : 'apartado',
            $entry['organization_id'],
            $entry['type'] === 'product' ? '#'.$entry['id'] : $entry['code'],
            $this->describe($entry),
            $entry['outcome'],
        ], $report->entries()));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function describe(array $entry): string
    {
        if ($entry['type'] === 'product') {
            return sprintf('faltante %d con %d apartadas', $entry['stock_shortfall'], $entry['reserved_units']);
        }

        $parts = [];

        /** @var array<string, array{stored: mixed, derived: mixed}> $fields */
        $fields = $entry['fields'];

        foreach ($fields as $column => $pair) {
            $parts[] = sprintf('%s %s → %s', $column, var_export($pair['stored'], true), var_export($pair['derived'], true));
        }

        /** @var list<array{number: int, stored: int, allocated: int}> $installments */
        $installments = $entry['installments'];

        foreach ($installments as $installment) {
            $parts[] = sprintf('cuota %d: %d pagados, %d repartidos', $installment['number'], $installment['stored'], $installment['allocated']);
        }

        if ($entry['paid_from_payments'] !== null && $entry['paid_from_payments'] !== $entry['paid_from_schedule']) {
            $parts[] = sprintf('pagos %d ≠ cuotas e inicial %d', $entry['paid_from_payments'], $entry['paid_from_schedule']);
        }

        if ($entry['overpaid_cents'] > 0) {
            $parts[] = sprintf('pagado de más %d', $entry['overpaid_cents']);
        }

        return implode('; ', $parts);
    }
}
