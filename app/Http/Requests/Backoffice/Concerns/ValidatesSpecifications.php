<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice\Concerns;

use App\Actions\Product\SpecificationInput;
use App\Actions\Product\SyncSpecificationsAction;

/**
 * Las reglas de las filas de ficha técnica y su traducción
 * (`REQ-BOF-021` RB-2, RB-3, SP-1, SP-2, SP-3).
 *
 * Va en un *concern* y no dentro de `StoreProductRequest` porque las dos
 * peticiones del producto —alta y edición— comparten exactamente estas cuatro
 * reglas, y porque tenerlas juntas deja a la vista lo que de otro modo se lee
 * como validación de relleno: **cada límite de aquí tiene un porqué**.
 *
 * ## Los errores llevan el índice de la fila
 *
 * SP-1 lo pide y es lo que hace utilizable un formulario de cincuenta filas:
 * `specifications.7.label` señala la octava, y la pantalla puede marcarla. Un
 * mensaje que dijera «falta una característica» dejaría a la persona buscando
 * cuál.
 *
 * ## `sometimes`, y la diferencia entre «vacía» y «ausente»
 *
 * Una lista **vacía** es un guardado válido que borra la ficha entera (SP-3).
 * Una lista **ausente** es otra cosa: el llamante no está gestionando la ficha
 * en esta operación —un seeder, una importación— y borrarla sería destruir
 * datos que nadie pidió tocar. El formulario del producto siempre manda la
 * clave, aunque sea con cero filas, así que el reemplazo funciona entero.
 *
 * ## Y el `label` duplicado **no** se rechaza
 *
 * RB-7: hay fichas legítimas con la misma etiqueta en unidades distintas
 * —«Peso: 2 kg» y «Peso: 4.4 lb»—, así que el servidor lo permite y la
 * pantalla solo avisa. Rechazarlo sería decidir por el negocio cómo describe
 * lo que vende.
 */
trait ValidatesSpecifications
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function specificationRules(): array
    {
        return [
            // RB-3 · el tope es **técnico**, no de plan: nadie desbloquea la
            // fila 51 pagando más, y por eso no pasa por `LimitEnforcer`.
            'specifications' => ['sometimes', 'array', 'max:'.SyncSpecificationsAction::MAX_ROWS],

            'specifications.*.group' => ['nullable', 'string', 'max:80'],

            // RB-2 · los dos obligatorios y con su tope de columna. El del
            // `value` es generoso a propósito: `REQ-CAT-004` SP-1 permite un
            // texto largo con saltos de línea y prohíbe recortarlo en
            // silencio.
            'specifications.*.label' => ['required', 'string', 'max:60'],
            'specifications.*.value' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function specificationAttributes(): array
    {
        return [
            'specifications' => 'ficha técnica',
            'specifications.*.group' => 'sección',
            'specifications.*.label' => 'característica',
            'specifications.*.value' => 'valor',
        ];
    }

    /**
     * Las filas ya normalizadas, o `null` si la petición no trae ficha.
     *
     * @return list<SpecificationInput>|null
     */
    protected function specifications(): ?array
    {
        if (! $this->has('specifications')) {
            return null;
        }

        $rows = $this->input('specifications', []);

        if (! is_array($rows)) {
            return [];
        }

        $inputs = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $inputs[] = SpecificationInput::of(
                group: isset($row['group']) && is_scalar($row['group']) ? (string) $row['group'] : null,
                label: isset($row['label']) && is_scalar($row['label']) ? (string) $row['label'] : '',
                value: isset($row['value']) && is_scalar($row['value']) ? (string) $row['value'] : '',
            );
        }

        return $inputs;
    }
}
