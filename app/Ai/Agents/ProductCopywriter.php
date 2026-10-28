<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Domain\Catalog\CopywritingMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Timeout(45)]
#[Temperature(0.6)]
final class ProductCopywriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public const MAX_CHARACTERS = 1200;

    private const SHARED = <<<'TEXT'
        Eres el redactor de fichas de producto de una tienda venezolana que vende a crédito por cuotas.
        Escribe en español neutro de Venezuela, tuteando al cliente, con tono cercano, honesto y persuasivo.

        Reglas de la descripción (description_html):
        - Entre 2 y 4 párrafos cortos; puedes incluir como máximo una lista con viñetas.
        - Solo HTML con estas etiquetas: p, strong, em, ul, ol, li. Nada de títulos, enlaces, estilos ni otras etiquetas.
        - Como máximo 1200 caracteres de texto (sin contar etiquetas).
        - No inventes datos: marca, modelo, capacidad, medidas, materiales o compatibilidades solo si se ven con claridad en las fotos o están escritos en los datos.
        - No menciones precios, cuotas, descuentos, envíos, garantías ni disponibilidad.
        - Sin emojis, sin mayúsculas sostenidas y sin signos de exclamación repetidos.

        Reglas de las características (characteristics):
        - Entre 3 y 8 datos breves de una sola línea, de hasta 80 caracteres cada uno, sin punto final.
        - Solo hechos visibles en las fotos o dichos en los datos, por ejemplo «Color azul marino» o «Talla M».
        - Si no hay suficientes hechos seguros, devuelve menos; nunca rellenes con suposiciones.

        Reglas de las etiquetas (tags):
        - Entre 2 y 6 etiquetas cortas (de una a tres palabras, hasta 40 caracteres) que ayuden a encontrar el producto en el catálogo, por ejemplo «mochilas», «viaje» o «impermeable».
        - En minúsculas, salvo nombres propios o marcas; sin numeral (#) ni signos.
        - Si alguna de las etiquetas que ya usa el negocio describe bien el producto, úsala escrita exactamente igual en vez de crear una parecida.
        - No repitas la categoría ni el nombre completo del producto.

        Responde únicamente con el objeto JSON pedido.
        TEXT;

    private const SUGGEST = <<<'TEXT'
        Tarea: a partir de las fotos (si las hay), el nombre, la categoría y la descripción actual (si la hay), escribe una descripción nueva para la ficha.
        TEXT;

    private const IMPROVE = <<<'TEXT'
        Tarea: reescribe la descripción que escribió la persona para que sea más clara y atractiva.
        Conserva todos y cada uno de los datos que ella escribió, sin contradecirlos ni quitarlos.
        No añadas nada nuevo salvo lo que sea evidente en las fotos.
        Extrae también las características del texto y de las fotos.
        TEXT;

    public function __construct(private readonly CopywritingMode $mode = CopywritingMode::Suggest) {}

    public function instructions(): string
    {
        $task = $this->mode === CopywritingMode::Improve ? self::IMPROVE : self::SUGGEST;

        return $task."\n\n".self::SHARED;
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'description_html' => $schema->string()
                ->description('Descripción en HTML con solo p, strong, em, ul, ol y li.')
                ->required(),
            'characteristics' => $schema->array()
                ->items($schema->string()->max(80))
                ->max(8)
                ->description('Características breves, una por elemento.')
                ->required(),
            'tags' => $schema->array()
                ->items($schema->string()->max(40))
                ->max(6)
                ->description('Etiquetas cortas para encontrar el producto en el catálogo.')
                ->required(),
        ];
    }
}
