<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Organization\UpsertPaymentAccountData;
use App\Domain\Payment\PaymentMethod;
use App\Rules\ValidPaymentIdentifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Los datos de una cuenta de cobro que llegan del formulario
 * (`REQ-ORG-007` RB-2, RB-3, RB-7, SP-5).
 *
 * **`holder_name` y `holder_document` son obligatorios en los seis métodos**
 * (RB-3), y eso no es burocracia trasplantada de un formulario de banco: en
 * pago móvil venezolano el titular y su cédula **son parte del dato de pago**,
 * no metadatos. Quien recibe un número sin titular no puede completar la
 * transferencia; el banco se los pide en la misma pantalla. Y en los demás
 * métodos siguen siendo lo que le dice al cliente que está pagando a quien
 * cree que está pagando.
 *
 * **El formato del `identifier` se valida aquí y no en la acción**, al revés
 * que los rangos de los ajustes, y la diferencia es el destinatario: un formato
 * mal escrito lo corrige la persona que lo está escribiendo, y para eso el
 * error tiene que llegar junto a su campo. La regla que lo decide es
 * `ValidPaymentIdentifier`, que es el único sitio donde vive cada formato.
 *
 * El permiso no se comprueba en este archivo: lo hacen el middleware `owner`
 * del grupo de rutas y la policy dentro de la acción (`REQ-ORG-003` RB-4).
 */
final class UpsertPaymentAccountRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', new Enum(PaymentMethod::class)],
            'bank' => ['nullable', 'string', 'max:100'],

            // RB-3 · los dos, en los seis métodos.
            'holder_name' => ['required', 'string', 'max:255'],
            'holder_document' => ['required', 'string', 'max:32'],

            'identifier' => $this->identifierRules(),

            // RB-7 · el tope es de la columna y del requisito a la vez: es una
            // nota que se le enseña al cliente junto a los datos de pago, no un
            // campo de texto largo.
            'instructions' => ['nullable', 'string', 'max:300'],

            'is_active' => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Cómo se nombra cada campo en los mensajes de error.
     *
     * El del `identifier` **cambia con el método** (RB-2): «el número de cuenta
     * es obligatorio» y «el teléfono es obligatorio» son el mismo campo, y
     * decirle «identificador» a quien está escribiendo su teléfono es hablarle
     * del esquema en vez de del formulario.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $method = $this->paymentMethod();

        return [
            'holder_name' => 'titular',
            'holder_document' => 'cédula o RIF del titular',
            'identifier' => $method instanceof PaymentMethod
                ? mb_strtolower($method->identifierLabel())
                : 'identificador',
        ];
    }

    /**
     * Los datos ya normalizados, tal como los espera la acción.
     *
     * `from` y no `tryFrom`: aquí ya se ha validado, y si el método no
     * existiera el problema sería que la validación no corrió — que es algo
     * que conviene que reviente donde pasa y no tres llamadas más allá.
     */
    public function toData(): UpsertPaymentAccountData
    {
        return new UpsertPaymentAccountData(
            method: PaymentMethod::from((string) $this->string('method')),
            bank: $this->nullableString('bank'),
            holderName: trim((string) $this->string('holder_name')),
            holderDocument: trim((string) $this->string('holder_document')),
            identifier: trim((string) $this->string('identifier')),
            instructions: $this->nullableString('instructions'),
            isActive: $this->boolean('is_active'),
            position: $this->input('position') === null ? null : $this->integer('position'),
        );
    }

    /**
     * Reglas del `identifier`, con el formato de **su** método.
     *
     * El método se resuelve con `tryFrom` y no con `from`: cuando llega uno
     * inexistente, quien tiene que hablar es la regla de `method` con su
     * mensaje, no una excepción sin capturar mientras se construyen las reglas.
     * Sin método no hay formato que comprobar, y el resto de las reglas siguen
     * aplicándose.
     *
     * @return list<mixed>
     */
    private function identifierRules(): array
    {
        $rules = ['required', 'string', 'max:255'];

        $method = $this->paymentMethod();

        if ($method instanceof PaymentMethod) {
            $rules[] = new ValidPaymentIdentifier($method);
        }

        return $rules;
    }

    private function paymentMethod(): ?PaymentMethod
    {
        return PaymentMethod::tryFrom((string) $this->string('method'));
    }

    /**
     * Texto opcional: vacío y ausente significan lo mismo y se guardan igual.
     *
     * Un `''` en `bank` sería un banco que se llama nada, y la pantalla lo
     * pintaría como un campo relleno. `null` es lo que la columna ya declara
     * para «no aplica».
     */
    private function nullableString(string $key): ?string
    {
        $value = trim((string) $this->string($key));

        return $value === '' ? null : $value;
    }
}
