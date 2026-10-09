<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Support\DocumentKind;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Una ficha de cliente con su evidencia de consentimiento (`REQ-IDN-005`
 * RB-2: una ficha no nace sin ella). La organización la pone el trait, desde
 * el tenant fijado.
 *
 * En la aplicación las fichas las crea `ResolveCustomerAction` al apartar;
 * esto es para los tests que necesitan una ficha sin pasar por el formulario
 * público —el aislamiento cruzado, el directorio, la fusión (`T-065`)—.
 *
 * @extends Factory<Customer>
 */
final class CustomerFactory extends Factory
{
    use CreatesWithinTenant;

    protected $model = Customer::class;

    /**
     * @return array<model-property<Customer>, mixed>
     */
    public function definition(): array
    {
        return [
            'document_number' => 'V'.fake()->unique()->numberBetween(10_000_000, 99_999_999),
            'document_kind' => DocumentKind::Cedula,
            'full_name' => fake()->name(),
            'phone' => '+58414'.fake()->numerify('#######'),
            'email' => null,
            'terms_accepted_at' => now(),
            'terms_ip_address' => '127.0.0.1',
        ];
    }
}
