<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptStatus;
use App\Models\Layaway;
use App\Models\Receipt;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un comprobante en revisión de un apartado de `LayawayFactory`: un pago móvil
 * de Bs. 850,00 con su banco y su referencia (`REQ-PAY-001`).
 *
 * Existe para los mecanismos que necesitan **un comprobante cualquiera** —el
 * de `CrossTenantAccessTest`, cuando una ruta lo enlace (`T-059`, `T-060`)—.
 * **No hay archivo**: la ruta tiene la forma de las de verdad
 * —`{organization_id}/{uuid}.jpg`, la de `ReceiptFile::pathFor()`, con la
 * organización del apartado— pero nada la escribe, y el hash es aleatorio. La
 * subida de verdad es `SubmitReceiptAction` (`T-059`).
 *
 * La organización y la ficha son las del apartado, leídas de su fila: sin
 * contexto, `CreatesWithinTenant` crearía una organización nueva y el
 * comprobante quedaría en un negocio y su apartado en otro.
 *
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Receipt> */
    protected $model = Receipt::class;

    /**
     * @return array<model-property<Receipt>, mixed>
     */
    public function definition(): array
    {
        return [
            'layaway_id' => Layaway::factory(),
            'organization_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('organization_id'),
            'customer_id' => fn (array $attributes): mixed => DB::table('layaways')->where('id', $attributes['layaway_id'])->value('customer_id'),
            'payment_account_id' => null,
            'payment_method' => PaymentMethod::PagoMovil,
            'bank' => 'Banesco',
            'reference' => fake()->unique()->numerify('##########'),
            'paid_at' => now()->toDateString(),
            'declared_amount' => '850.00',
            'declared_currency' => Currency::VES,
            'note' => null,
            // Después de `organization_id`: la recibe ya resuelta.
            'file_path' => fn (array $attributes): string => ReceiptFile::pathFor((int) $attributes['organization_id'], 'jpg'),
            'file_hash' => hash('sha256', (string) Str::uuid()),
            'file_mime' => 'image/jpeg',
            'file_size' => 120_000,
            'status' => ReceiptStatus::PendingReview,
        ];
    }

    /**
     * En efectivo: sin banco ni referencia, como exige el `CHECK` de la tabla.
     */
    public function cash(): static
    {
        return $this->state(fn (): array => [
            'payment_method' => PaymentMethod::EfectivoUsd,
            'bank' => null,
            'reference' => null,
            'declared_amount' => '30.00',
            'declared_currency' => Currency::USD,
        ]);
    }

    public function withStatus(ReceiptStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
