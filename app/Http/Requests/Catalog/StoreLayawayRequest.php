<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Customer\TermsConsent;
use App\Models\Catalog;
use App\Models\Product;
use App\Rules\ValidDocumentNumber;
use App\Rules\ValidVenezuelanPhone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El formulario de apartado, validado (`REQ-LAY-001` RB-3, RB-5, RB-12;
 * `REQ-IDN-005` RB-1).
 *
 * Valida **la forma** de cada campo, y nada más: que haya stock, que el
 * catálogo siga abierto o que el plan del negocio admita otro apartado solo es
 * cierto bajo el bloqueo, y lo decide `CreateLayawayAction`. Si esta clase
 * mirase el stock, lo diría con un dato que puede cambiar antes de que la
 * acción lo bloquee, y el cliente vería dos rechazos distintos para la misma
 * causa.
 *
 * ## Las reglas dicen lo mismo que los objetos que construyen
 *
 * El documento y el teléfono pasan por `ValidDocumentNumber` y
 * `ValidVenezuelanPhone`, que preguntan a `DocumentNumber` y `PhoneNumber`.
 * El correo va con `email:filter`, que es `FILTER_VALIDATE_EMAIL`, lo mismo que
 * comprueba `CustomerContactData`: con la validación por defecto de Laravel,
 * un correo que esta clase aceptara podría rechazarlo el DTO después, ya sin
 * un campo al que atribuirlo.
 *
 * Los nombres de los campos son los de `LayawayFormValues` ([20],
 * `REQ-LAY-001`), en *camelCase*: así cada error vuelve con la misma clave que
 * el campo que lo pinta, sin una tabla de traducción en medio.
 *
 * ## La casilla es `accepted`, en el servidor (RB-1, SP-2)
 *
 * Una petición que la omita recibe el mismo `422` que una que la desmarque.
 * Solo después de pasarla se construye la evidencia, con la IP que da Laravel.
 * **Esa IP no es de fiar hasta que exista `TrustProxies`** (`REQ-IDN-005`
 * RB-6, `T-087`): detrás del proxy del despliegue sería la del proxy. Es SP-3
 * de ese requisito, una limitación conocida y escrita, no un fallo que se tape
 * aquí.
 */
final class StoreLayawayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RB-5 · el tope fijo; el del stock lo pone la acción bajo el
            // bloqueo y vuelve al mismo campo con las que quedan.
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CreateLayawayData::MAX_QUANTITY],
            'fullName' => ['required', 'string', 'max:'.CustomerContactData::MAX_NAME_LENGTH],
            'documentNumber' => ['required', 'string', new ValidDocumentNumber],
            'phone' => ['required', 'string', new ValidVenezuelanPhone],
            // SP-7 de `REQ-IDN-001` · opcional; en blanco es sin correo.
            'email' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_EMAIL_LENGTH, 'email:filter'],
            'termsAccepted' => ['accepted'],
            // RB-12 · el UUID que el formulario genera **una vez al montarse**.
            'idempotencyKey' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'quantity' => 'cantidad',
            'fullName' => 'nombre',
            'documentNumber' => 'documento',
            'phone' => 'teléfono',
            'email' => 'correo',
            'termsAccepted' => 'aceptación de las condiciones',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'termsAccepted.accepted' => 'Para apartar tienes que aceptar las condiciones.',
            'idempotencyKey.*' => 'El formulario se quedó a medias. Recarga la página y vuelve a intentarlo.',
        ];
    }

    /**
     * Los datos de la acción, con la evidencia del consentimiento construida
     * **ahora**, que es cuando se aceptó.
     */
    public function toData(Catalog $catalog, Product $product): CreateLayawayData
    {
        $email = $this->input('email');

        return new CreateLayawayData(
            catalog: $catalog,
            product: $product,
            quantity: $this->integer('quantity'),
            contact: CustomerContactData::fromInput(
                (string) $this->string('documentNumber'),
                (string) $this->string('fullName'),
                (string) $this->string('phone'),
                is_string($email) ? $email : null,
            ),
            consent: TermsConsent::acceptedNowFrom((string) $this->ip()),
            idempotencyKey: (string) $this->string('idempotencyKey'),
        );
    }
}
