<?php

declare(strict_types=1);

namespace Tests\Feature\Layaway;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CreateLayawayData;
use App\Domain\Customer\TermsConsent;
use App\Models\Catalog;
use App\Models\Product;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

final class CreateLayawayDataTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function quantities(): array
    {
        return [
            'ninguna' => [0, false],
            'una' => [1, true],
            'diez' => [10, true],
            'once' => [11, false],
            'negativa' => [-1, false],
        ];
    }

    #[DataProvider('quantities')]
    public function test_the_quantity_goes_from_one_to_ten(int $quantity, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $data = $this->data(quantity: $quantity);

        $this->assertSame($quantity, $data->quantity);
    }

    public function test_the_key_is_a_uuid(): void
    {
        $this->assertTrue(Str::isUuid($this->data()->idempotencyKey));

        $this->expectException(InvalidArgumentException::class);
        $this->data(key: 'mi-clave');
    }

    public function test_it_has_no_organization_field(): void
    {
        $fields = array_map(
            fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(CreateLayawayData::class, '__construct'))->getParameters(),
        );

        $this->assertSame(['catalog', 'product', 'quantity', 'contact', 'consent', 'idempotencyKey', 'account'], $fields);
    }

    private function data(int $quantity = 1, ?string $key = null): CreateLayawayData
    {
        return new CreateLayawayData(
            catalog: new Catalog,
            product: new Product,
            quantity: $quantity,
            contact: CustomerContactData::fromInput('V12345678', 'María González', '0414-1234567', null),
            consent: TermsConsent::acceptedNowFrom('190.202.10.5'),
            idempotencyKey: $key ?? (string) Str::uuid(),
        );
    }
}
