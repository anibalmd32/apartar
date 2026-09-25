<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quién hizo la última modificación, contando **la misma historia que
 * `updated_at`** (`REQ-BOF-020` SP-7, `REQ-CAT-023` SP-4).
 *
 * Las dos reglas piden la marca de última modificación **con su autor**, y la
 * fecha ya la escribe Eloquent. Lo que no puede pasar es que las dos columnas,
 * pintadas una al lado de la otra, se contradigan: una fecha de hoy con el
 * nombre de quien renombró el producto la semana pasada, o un autor nuevo
 * sobre una fecha que no se movió porque el guardado no cambió nada.
 *
 * ## Por eso el autor se declara y el modelo decide si se escribe
 *
 * La acción dice quién actúa —`editedBy($actor)`— antes de guardar, y el
 * modelo solo asigna `updated_by` **si el guardado va a escribir algo**: con
 * atributos sucios, o con el `updated_at` que mueve un `touch()`. Repetir un
 * paso ya aplicado no escribe nada (`T-030`), así que tampoco cambia el autor;
 * componer un catálogo lo toca (`T-031`), así que el autor es quien compuso.
 *
 * ## No sale de la auditoría
 *
 * Renombrar un producto o componer un catálogo **no son** eventos de la lista
 * cerrada de `REQ-BOF-005` RB-1, y no se añaden para esto: la lista es cerrada
 * a propósito. Esta columna responde «quién lo tocó por última vez», que es
 * otra pregunta.
 *
 * Un guardado sin `editedBy()` —una factory, un comando de mantenimiento— no
 * cambia el autor anterior. Quien lo olvide en una acción lo encuentra en los
 * tests de esa acción, que afirman el autor de cada escritura.
 *
 * @phpstan-require-extends Model
 */
trait RecordsLastEditor
{
    /**
     * Quien actúa en el próximo guardado, o `null` si nadie lo declaró.
     */
    private ?int $pendingEditorId = null;

    public static function bootRecordsLastEditor(): void
    {
        static::saving(static function (Model $model): void {
            /** @var static $model */
            if ($model->pendingEditorId !== null && $model->isDirty()) {
                $model->setAttribute('updated_by', $model->pendingEditorId);
            }
        });

        // Se olvida al terminar, se haya escrito o no: el autor declarado es el
        // de **este** guardado, y uno posterior sin declarar no lo hereda.
        static::saved(static function (Model $model): void {
            /** @var static $model */
            $model->pendingEditorId = null;
        });
    }

    /**
     * Declara quién hace el próximo guardado.
     */
    public function editedBy(User $actor): static
    {
        $this->pendingEditorId = (int) $actor->getKey();

        return $this;
    }

    /**
     * Marca el modelo como modificado **por** alguien, aunque ninguna de sus
     * columnas cambie.
     *
     * Es el `touch()` de quien compone un catálogo (`T-031`): el cambio vive en
     * el pivote, así que la fila solo cambia por la fecha. Y la fecha va en
     * segundos, de modo que tocar en el mismo segundo que la última escritura
     * no cambia nada que guardar —y con `editedBy()` el autor se quedaría
     * sin escribir—. Aquí el autor se asigna siempre: tocar es modificar.
     */
    public function touchedBy(User $actor): bool
    {
        $this->setAttribute('updated_by', (int) $actor->getKey());

        return $this->touch();
    }

    /**
     * Quién lo modificó por última vez.
     *
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
