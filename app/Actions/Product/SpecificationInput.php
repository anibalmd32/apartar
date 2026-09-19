<?php

declare(strict_types=1);

namespace App\Actions\Product;

use InvalidArgumentException;

/**
 * Una fila de la ficha técnica tal como llega del formulario
 * (`REQ-BOF-021`).
 *
 * Un par «característica: valor» con su sección opcional. Es lo que
 * `SyncSpecificationsAction` reemplaza en bloque, y existe por lo mismo que
 * `ProductData`: para que la acción reciba datos y no un array de arrays del
 * que haya que adivinar las claves.
 *
 * ## Lo que normaliza, y lo que **no** toca
 *
 * Normaliza los espacios: recorta los extremos y colapsa los de en medio, que
 * es lo que hace que «Motor » y «Motor» sean la misma sección al agruparlas.
 * Un `group` que queda vacío pasa a `null`, porque «sin sección» es un estado
 * y una cadena vacía es una sección que se llama nada — y en la ficha se
 * pintaría como un encabezado en blanco.
 *
 * **No sanea el `value`.** Es deliberado y es la mitad del escapado que se
 * hace mal: si un `<` se escapara al guardar, la medida «< 2 kg» quedaría
 * almacenada como `&lt; 2 kg` y el negocio vería su propio texto convertido en
 * otra cosa. Se guarda tal cual y **se escapa al renderizar**
 * (`REQ-CAT-004` RB-4), que en React es el comportamiento por defecto mientras
 * nadie escriba `dangerouslySetInnerHTML`.
 *
 * ## Y sí exige el `label`
 *
 * Es una precondición de la aritmética de la ficha, no la regla de negocio: el
 * `422` con el índice de la fila lo pone `ValidatesSpecifications` (SP-1), que
 * es donde el error llega junto a su campo. Si esto lanza, es que alguien
 * construyó filas sin pasar por la validación.
 */
final readonly class SpecificationInput
{
    /**
     * @param  string|null  $group  sección de la ficha; `null` es «General»
     * @param  string  $label  la característica: «Potencia», «Material»
     * @param  string  $value  su valor, tal como lo escribió el negocio
     *
     * @throws InvalidArgumentException si el `label` viene vacío
     */
    public function __construct(
        public ?string $group,
        public string $label,
        public string $value,
    ) {
        if ($label === '') {
            throw new InvalidArgumentException(
                'Una fila de ficha técnica necesita su característica. `REQ-BOF-021` RB-2 la exige y '
                .'ValidatesSpecifications la comprueba antes con el índice de la fila: si esto lanza, las '
                .'filas se construyeron sin pasar por la validación.'
            );
        }
    }

    /**
     * La fila con sus espacios ya normalizados.
     *
     * Se construye por aquí y no con el constructor cuando el texto viene de
     * fuera —del formulario, de un seeder—, que es siempre.
     */
    public static function of(?string $group, string $label, string $value): self
    {
        $group = self::collapse($group ?? '');

        return new self(
            group: $group === '' ? null : $group,
            label: self::collapse($label),
            // El valor conserva sus saltos de línea: `REQ-CAT-004` SP-1
            // permite un texto largo y recortarlo sería decidir por el
            // negocio dónde acaba la descripción de lo que vende.
            value: trim($value),
        );
    }

    /**
     * Los atributos con los que se escribe la fila, ya con su posición.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(int $productId, int $position): array
    {
        return [
            'product_id' => $productId,
            'group' => $this->group,
            'label' => $this->label,
            'value' => $this->value,
            'position' => $position,
        ];
    }

    /**
     * Recorta los extremos y colapsa los espacios de en medio.
     */
    private static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
