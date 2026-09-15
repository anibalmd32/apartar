<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los ajustes de operación que llegan del formulario (`REQ-ORG-006` RB-2).
 *
 * **No valida los rangos**, y es deliberado: los valida `UpdateSettingsAction`
 * contra `SettingKey::validationRules()`. Si los declarara también aquí
 * habría dos copias del rango de `default_frequency_days`, y la que se quedara
 * vieja sería la del formulario —la que decide qué se puede escribir—.
 *
 * Lo que sí hace es lo que un `FormRequest` sabe hacer mejor que nadie:
 * **quedarse con el sobre y no con el contenido**. El cuerpo llega bajo
 * `settings`, así que cualquier otra cosa que venga en la petición no llega a
 * la acción, y de las claves de dentro se ocupa RB-2 —lo desconocido se ignora
 * en silencio—.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
        ];
    }

    /**
     * Las claves crudas, tal como las escribió el formulario.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = $this->validated('settings');

        return $settings;
    }
}
