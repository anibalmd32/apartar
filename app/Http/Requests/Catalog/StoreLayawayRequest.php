<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Customer\TermsConsent;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\Product;
use App\Rules\ValidDocumentNumber;
use App\Rules\ValidVenezuelanPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

final class StoreLayawayRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CreateLayawayData::MAX_QUANTITY],
            'fullName' => ['required', 'string', 'max:'.CustomerContactData::MAX_NAME_LENGTH],
            'documentNumber' => ['required', 'string', new ValidDocumentNumber],
            'phone' => ['required', 'string', new ValidVenezuelanPhone],
            'email' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_EMAIL_LENGTH, 'email:filter'],
            'termsAccepted' => ['accepted'],
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

    public function toData(Catalog $catalog, Product $product): CreateLayawayData
    {
        $account = Auth::guard('account')->user();
        $account = $account instanceof Account ? $account : null;

        $email = $account instanceof Account ? $account->email : $this->input('email');

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
            account: $account,
        );
    }
}
