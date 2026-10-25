<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use App\Domain\Money\Money;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

trait StreamsRowsById
{
    public const CHUNK = 500;

    public int $rowCount = 0;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Generator<int, TModel>
     */
    protected function streamById(Builder $query): Generator
    {
        $table = $query->getModel()->getTable();

        foreach ($query->reorder()->lazyById(self::CHUNK, $table.'.id', 'id') as $model) {
            $this->rowCount++;

            yield $model;
        }
    }

    /**
     * @param  array<string, string>  $meta
     * @param  list<string>  $columns
     * @return list<list<string>>
     */
    protected function headingRows(string $title, array $meta, array $columns): array
    {
        $rows = [[$title]];

        foreach ($meta as $label => $value) {
            $rows[] = [$label, $value];
        }

        $rows[] = [''];
        $rows[] = $columns;

        return $rows;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }

    protected static function dollars(Money|int $amount): float
    {
        return round(($amount instanceof Money ? $amount->cents : $amount) / 100, 2);
    }
}
