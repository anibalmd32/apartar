<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Pasar a un modelo lo que dice la fila, después de escribirla por otra copia
 * —la bloqueada, la releída—.
 *
 * Es el gesto con el que las Actions devuelven a quien las llamó el modelo
 * que les pasó, ya al día: `setRawAttributes()` y `syncOriginal()`. **En dos
 * pasos, y no con `setRawAttributes(..., sync: true)`**: con `sync`,
 * `syncOriginal()` lee `getAttributes()` antes de que Laravel vacíe la caché
 * de los casts de clase, y un atributo que quien llama ya había leído —un
 * `paid_cents` de `MoneyCast`— se volvía a escribir encima del nuevo. El
 * modelo devolvía el saldo viejo con la fila ya corregida, en silencio
 * (detectado en `T-064`).
 */
final class ModelState
{
    /**
     * @param  array<string, mixed>  $attributes  los de la fila, crudos
     */
    public static function adopt(Model $model, array $attributes): void
    {
        $model->setRawAttributes($attributes);
        $model->syncOriginal();
    }
}
