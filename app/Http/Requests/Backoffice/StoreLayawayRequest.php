<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\BackofficeLayawayData;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\DownPaymentWindow;
use App\Http\Requests\Backoffice\Concerns\ValidatesManualPayment;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Product;
use App\Rules\ValidDocumentNumber;
use App\Rules\ValidVenezuelanPhone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class StoreLayawayRequest extends FormRequest
{
    use ValidatesManualPayment;

    public const CUSTOMER_MODES = ['existing', 'new'];

    public const WINDOWS = ['default', 'hours', 'days', 'none'];

    public function authorize(): bool
    {
        return $this->user()?->can('create', Layaway::class) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $new = $this->input('customer_mode') === 'new';
        $window = $this->input('window');

        $rules = [
            'product' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CreateLayawayData::MAX_QUANTITY],
            'customer_mode' => ['required', 'string', Rule::in(self::CUSTOMER_MODES)],
            'customer' => [Rule::requiredIf(! $new), 'nullable', 'integer'],
            'full_name' => [Rule::requiredIf($new), 'nullable', 'string', 'max:'.CustomerContactData::MAX_NAME_LENGTH],
            'document_number' => [Rule::requiredIf($new), 'nullable', 'string', ...($new ? [new ValidDocumentNumber] : [])],
            'phone' => [Rule::requiredIf($new), 'nullable', 'string', ...($new ? [new ValidVenezuelanPhone] : [])],
            'email' => ['nullable', 'string', 'max:'.CustomerContactData::MAX_EMAIL_LENGTH, 'email:filter'],
            'terms_accepted' => $new ? ['accepted'] : ['nullable'],
            'window' => ['required', 'string', Rule::in(self::WINDOWS)],
            'window_value' => match ($window) {
                'hours' => ['required', 'integer', 'min:1', 'max:'.DownPaymentWindow::MAX_HOURS],
                'days' => ['required', 'integer', 'min:1', 'max:'.DownPaymentWindow::MAX_DAYS],
                default => ['nullable'],
            },
            'with_payment' => ['boolean'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ];

        if (! $this->boolean('with_payment')) {
            return $rules;
        }

        $today = CarbonImmutable::now($this->organizationTimezone())->toDateString();

        return [...$rules, ...$this->paymentRules($today, $today)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'product' => 'producto',
            'quantity' => 'cantidad',
            'customer' => 'cliente',
            'full_name' => 'nombre',
            'document_number' => 'documento',
            'phone' => 'teléfono',
            'email' => 'correo',
            'window' => 'plazo de la inicial',
            'window_value' => 'plazo de la inicial',
            ...$this->paymentAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer.required' => 'Elige la ficha del cliente.',
            'terms_accepted.accepted' => 'Marca que el cliente aceptó las condiciones del apartado.',
            'idempotency_key.*' => 'El formulario se quedó a medias. Recarga la página y vuelve a intentarlo.',
            ...$this->paymentMessages(),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function toData(): BackofficeLayawayData
    {
        $new = $this->input('customer_mode') === 'new';

        try {
            return new BackofficeLayawayData(
                product: $this->product(),
                quantity: $this->integer('quantity'),
                customer: $new ? null : $this->customer(),
                contact: $new ? $this->contact() : null,
                consent: $new ? TermsConsent::acceptedNowFrom((string) $this->ip()) : null,
                window: $this->window(),
                idempotencyKey: $this->string('idempotency_key')->toString(),
                payment: $this->boolean('with_payment') ? $this->paymentData() : null,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['window_value' => $exception->getMessage()]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function product(): Product
    {
        $product = Product::query()->find($this->integer('product'));

        if (! $product instanceof Product) {
            throw ValidationException::withMessages(['product' => 'Ese producto no es de tu negocio.']);
        }

        return $product;
    }

    /**
     * @throws ValidationException
     */
    private function customer(): Customer
    {
        $customer = Customer::query()->find($this->integer('customer'));

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages(['customer' => 'Esa ficha de cliente no es de tu negocio.']);
        }

        return $customer;
    }

    /**
     * @throws ValidationException
     */
    private function contact(): CustomerContactData
    {
        try {
            return CustomerContactData::fromInput(
                $this->string('document_number')->toString(),
                $this->string('full_name')->toString(),
                $this->string('phone')->toString(),
                $this->nullableString('email'),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['full_name' => $exception->getMessage()]);
        }
    }

    private function window(): DownPaymentWindow
    {
        return match ($this->input('window')) {
            'hours' => DownPaymentWindow::hours($this->integer('window_value')),
            'days' => DownPaymentWindow::days($this->integer('window_value')),
            'none' => DownPaymentWindow::withoutExpiry(),
            default => DownPaymentWindow::organizationDefault(),
        };
    }
}
