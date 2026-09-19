<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Product\ProductData;
use App\Domain\Money\Money;
use App\Http\Requests\Backoffice\Concerns\ValidatesSpecifications;
use App\Rules\CoherentFinancingTerms;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los datos de un producto nuevo (`REQ-BOF-020`).
 *
 * ## Los importes se validan por su **forma**; los rangos son de la regla
 *
 * Aquí solo se comprueba que `price` sea un decimal de hasta dos cifras, y eso
 * es SP-8 entero: «19.999» **se rechaza en lugar de redondearse en silencio**,
 * porque redondear es decidir por el negocio el precio de lo que vende. Los
 * seis rangos cruzados —inicial menor que el precio, tachado mayor, cuotas
 * entre 1 y 36…— son de `CoherentFinancingTerms`, que los tiene todos juntos y
 * puede hablar de cinco campos a la vez.
 *
 * `decimal:0,2` y `Money::fromDecimalString()` dicen lo mismo con dos lenguajes
 * distintos, y las dos hacen falta: la regla pone el `422` junto al campo y el
 * constructor impide que una cadena rara llegue a la columna por otra puerta
 * —un seeder, una importación— sin pasar por un formulario.
 *
 * ## El `slug` no se acepta al crear
 *
 * RB-3: se autogenera del nombre. Admitirlo aquí daría una tercera forma de
 * fijarlo que nadie pidió. `UpdateProductRequest` sí lo acepta, porque editarlo
 * es una acción explícita —y se cierra al publicar (SP-5)—.
 *
 * El permiso no se comprueba en este archivo: lo hacen el grupo de rutas y la
 * policy dentro de la acción (`REQ-ORG-003` RB-4).
 */
class StoreProductRequest extends FormRequest
{
    use ValidatesSpecifications;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],

            // El SKU es único **por organización** y eso lo comprueba la
            // acción, no una regla: el índice de la base cuenta también los
            // productos archivados, y una regla que los ignorase daría el
            // `422` correcto casi siempre y un error de base de datos justo
            // cuando alguien reutiliza el código de algo que archivó.
            'sku' => ['required', 'string', 'max:64'],

            'description' => ['nullable', 'string', 'max:5000'],

            // La coherencia cruzada se ata a `price` y habla de cinco campos
            // (RB-1); atarla a cada uno la ejecutaría cinco veces y duplicaría
            // cada mensaje.
            'price' => ['required', 'numeric', 'decimal:0,2', new CoherentFinancingTerms],
            'compare_at_price' => ['nullable', 'numeric', 'decimal:0,2'],
            'down_payment' => ['required', 'numeric', 'decimal:0,2'],

            'installments_count' => ['required', 'integer'],
            'frequency_days' => ['required', 'integer'],
            'stock' => ['required', 'integer'],

            // Las dos mitades de la taxonomía: lo elegido de la lista y lo
            // escrito al vuelo (`REQ-BOF-023` RB-8). Que el identificador sea
            // de esta organización lo comprueba la acción, que es donde
            // `REQ-ORG-002` RB-7 pone la guarda de escritura del pivote.
            'category_id' => ['nullable', 'integer'],
            'category_name' => ['nullable', 'string', 'max:120'],

            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer'],
            'tag_names' => ['array'],
            'tag_names.*' => ['string', 'max:80'],
            // La ficha técnica viaja con el producto y no en un endpoint
            // aparte (`REQ-BOF-021` RB-8): sus cuatro reglas están en el
            // *concern*, con el porqué de cada tope.
        ] + $this->specificationRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'sku' => 'SKU',
            'price' => 'precio',
            'compare_at_price' => 'precio tachado',
            'down_payment' => 'cuota inicial',
            'installments_count' => 'número de cuotas',
            'frequency_days' => 'días entre cuotas',
            'stock' => 'existencias',
            'slug' => 'enlace',
        ] + $this->specificationAttributes();
    }

    /**
     * Los datos ya normalizados, tal como los espera la acción.
     */
    public function toData(): ProductData
    {
        return $this->productData($this->requestedSlug());
    }

    /**
     * El enlace pedido; ninguno al crear (RB-3).
     */
    protected function requestedSlug(): ?string
    {
        return null;
    }

    /**
     * La traducción de la petición a `ProductData`, compartida por el alta y
     * la edición.
     */
    protected function productData(?string $slug): ProductData
    {
        return new ProductData(
            name: trim((string) $this->string('name')),
            sku: trim((string) $this->string('sku')),
            slug: $slug,
            description: $this->nullableString('description'),
            price: $this->amount('price'),
            compareAtPrice: $this->nullableAmount('compare_at_price'),
            downPayment: $this->amount('down_payment'),
            installmentsCount: $this->integer('installments_count'),
            frequencyDays: $this->integer('frequency_days'),
            stock: $this->integer('stock'),
            categoryId: $this->input('category_id') === null ? null : $this->integer('category_id'),
            categoryName: $this->nullableString('category_name'),
            tagIds: $this->integerList('tag_ids'),
            tagNames: $this->stringList('tag_names'),
            specifications: $this->specifications(),
        );
    }

    /**
     * Un importe obligatorio, ya en céntimos.
     *
     * `fromDecimalString` y no `(int) ($valor * 100)`: ese float es justo el
     * redondeo que `REQ-FX-001` prohíbe. Aquí ya se ha validado la forma, así
     * que si lanzara sería porque la validación no corrió — y eso conviene que
     * reviente donde pasa y no tres capas más allá.
     */
    protected function amount(string $key): Money
    {
        return Money::fromDecimalString(trim((string) $this->string($key)));
    }

    /**
     * Un importe opcional: vacío y ausente significan lo mismo.
     *
     * Y ese «lo mismo» es `null`, no cero: «sin precio de comparación» y
     * «precio de comparación de cero dólares» son cosas distintas, y la
     * segunda se pinta como un descuento del 100 %.
     */
    protected function nullableAmount(string $key): ?Money
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : Money::fromDecimalString($value);
    }

    protected function nullableString(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<int>
     */
    protected function integerList(string $key): array
    {
        $values = $this->input($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(intval(...), $values));
    }

    /**
     * @return list<string>
     */
    protected function stringList(string $key): array
    {
        $values = $this->input($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(fn (mixed $value): string => trim((string) $value), $values));
    }
}
