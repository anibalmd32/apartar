<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Catalog\PublishCatalogData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Con qué se publica un catálogo (`REQ-CAT-022` RB-3, RB-6).
 *
 * Dos campos, y ninguno de los dos es la fecha de publicación: la
 * programación de la publicación está **fuera de alcance** por escrito —«solo
 * se programa la expiración»— y quien fija `published_at` es la acción.
 *
 * ## La fecha pasada se valida en la acción y no aquí
 *
 * `date` comprueba que la cadena se entienda; que **no haya pasado** es RB-4.3
 * y `REQ-CAT-022` dice que los seis requisitos se verifican en la `Action`. No
 * es purismo: SP-8 —republicar un expirado sin mover la fecha— es la misma
 * condición mirada desde el otro lado, y con un `after:now` aquí el mensaje
 * sería «el campo debe ser una fecha posterior» en vez de explicar que el
 * enlace nacería dando `410`.
 *
 * Que la casilla de la vitrina **no** sea obligatoria es deliberado: un
 * `checkbox` sin marcar no se envía, así que exigirla haría imposible publicar
 * como `Unlisted` desde el formulario, que es justo el modo con el que se
 * reparte una campaña por WhatsApp (RB-3).
 */
final class PublishCatalogRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'is_listed' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expires_at.date' => 'La fecha de caducidad no se entiende.',
        ];
    }

    /**
     * La visibilidad y la caducidad pedidas.
     *
     * **Un día suelto se toma entero.** El selector del formulario manda
     * `YYYY-MM-DD`, y quien escribe «30 de septiembre» quiere vender el día 30:
     * guardar las 00:00 de esa fecha apagaría la campaña una jornada antes de
     * lo que el negocio cree. La hora la pone el servidor y no el navegador de
     * quien publica, por lo mismo que el resto de las fechas del proyecto.
     *
     * Una fecha **con hora** se respeta tal cual. Es lo que llega de un
     * cliente que la compone a mano, y estirarla al final del día convertiría
     * una hora ya pasada en una futura — justo la que SP-5 tiene que rechazar.
     */
    public function toData(): PublishCatalogData
    {
        $expiresAt = $this->date('expires_at');

        if ($expiresAt === null) {
            return new PublishCatalogData(isListed: $this->boolean('is_listed'), expiresAt: null);
        }

        $expiresAt = CarbonImmutable::instance($expiresAt);

        return new PublishCatalogData(
            isListed: $this->boolean('is_listed'),
            expiresAt: $this->expiresOnAWholeDay() ? $expiresAt->endOfDay() : $expiresAt,
        );
    }

    /**
     * Si lo que llegó es un día sin hora.
     */
    private function expiresOnAWholeDay(): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $this->string('expires_at'))) === 1;
    }
}
