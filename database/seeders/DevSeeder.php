<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Catalog\CatalogData;
use App\Actions\Catalog\PublishCatalogAction;
use App\Actions\Catalog\PublishCatalogData;
use App\Actions\Catalog\SyncCatalogProductsAction;
use App\Actions\Catalog\UpsertCatalogAction;
use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\CancelLayawayAction;
use App\Actions\Layaway\CreateLayawayAction;
use App\Actions\Layaway\CreateLayawayData;
use App\Actions\Layaway\DetectOverdueAction;
use App\Actions\Layaway\ExpireLayawaysAction;
use App\Actions\Layaway\MarkDeliveredAction;
use App\Actions\Organization\RegisterOrganizationAction;
use App\Actions\Organization\RegisterOrganizationData;
use App\Actions\Organization\UpsertPaymentAccountAction;
use App\Actions\Organization\UpsertPaymentAccountData;
use App\Actions\Payment\ApproveReceiptAction;
use App\Actions\Payment\ApproveReceiptData;
use App\Actions\Payment\ManualPaymentData;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Actions\Payment\SubmitReceiptAction;
use App\Actions\Payment\SubmitReceiptData;
use App\Actions\Product\ChangeProductStatusAction;
use App\Actions\Product\ProductData;
use App\Actions\Product\UpsertProductAction;
use App\Actions\Subscription\ApproveSubscriptionReceiptAction;
use App\Actions\Subscription\ApproveSubscriptionReceiptData;
use App\Actions\Subscription\ChangePlanAction;
use App\Actions\Subscription\SubmitSubscriptionReceiptAction;
use App\Actions\Subscription\SubmitSubscriptionReceiptData;
use App\Domain\Customer\TermsConsent;
use App\Domain\Exchange\RateSource;
use App\Domain\Money\Currency;
use App\Domain\Money\Money;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\ExchangeRate;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\PlatformUser;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\DocumentNumber;
use App\Support\Media\TenantPathGenerator;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DevSeeder extends Seeder
{
    public const PASSWORD = 'Apartar2026demo';

    public const OPERATOR_EMAIL = 'operador@apartar.test';

    private const HISTORY_DAYS = 20;

    private const IP = '127.0.0.1';

    private const QUEUE_CONNECTION = 'database';

    /**
     * @var list<array{name: string, slug: string, document: string, owner: array{0: string, 1: string}, member: array{0: string, 1: string}, approved: bool, categories: array<string, list<array{0: string, 1: int, 2: int, 3: int}>>}>
     */
    private const BUSINESSES = [
        [
            'name' => 'Moda Valentina',
            'slug' => 'moda-valentina',
            'document' => 'J-412345678',
            'owner' => ['Valentina Rojas', 'valentina@apartar.test'],
            'member' => ['Ana Pérez', 'ana@apartar.test'],
            'approved' => true,
            'categories' => [
                'Vestidos' => [['Vestido floral de verano', 4_500, 1_000, 8], ['Vestido negro de noche', 7_800, 2_000, 5]],
                'Calzado' => [['Sandalias de cuero', 3_200, 800, 10], ['Botines de gamuza', 9_500, 2_500, 4]],
                'Accesorios' => [['Bolso tejido artesanal', 2_800, 700, 12], ['Lentes de sol polarizados', 1_900, 500, 15]],
            ],
        ],
        [
            'name' => 'Tecno Hogar',
            'slug' => 'tecno-hogar',
            'document' => 'V-18765432',
            'owner' => ['Carlos Méndez', 'carlos@apartar.test'],
            'member' => ['Luis Gómez', 'luis@apartar.test'],
            'approved' => false,
            'categories' => [
                'Electrodomésticos' => [['Licuadora de 10 velocidades', 6_500, 1_500, 6], ['Freidora de aire 5 L', 12_000, 3_000, 4]],
                'Televisores' => [['Televisor 43" 4K', 32_000, 8_000, 3], ['Televisor 55" 4K', 48_000, 12_000, 2]],
                'Celulares' => [['Teléfono gama media 128 GB', 22_000, 5_500, 5], ['Audífonos inalámbricos', 4_200, 1_000, 10]],
            ],
        ],
    ];

    /**
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const CUSTOMERS = [
        ['V-20123456', 'María Fernanda López', '04141234501', 'maria.lopez@cliente.test'],
        ['V-21234567', 'José Gregorio Martínez', '04241234502', 'jose.martinez@cliente.test'],
        ['V-22345678', 'Daniela Herrera', '04121234503', 'daniela.herrera@cliente.test'],
        ['V-23456789', 'Andrés Castillo', '04161234504', 'andres.castillo@cliente.test'],
        ['V-24567890', 'Gabriela Suárez', '04261234505', 'gabriela.suarez@cliente.test'],
        ['V-25678901', 'Ricardo Blanco', '04141234506', 'ricardo.blanco@cliente.test'],
        ['E-81234567', 'Sofía Rinaldi', '04241234507', 'sofia.rinaldi@cliente.test'],
        ['V-26789012', 'Pedro Ramírez', '04121234508', 'pedro.ramirez@cliente.test'],
    ];

    /**
     * @var list<array{business: string, document: string, code: string}>
     */
    private array $portalCodes = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('El escenario de desarrollo no se siembra en producción.');
        }

        config([
            'queue.default' => self::QUEUE_CONNECTION,
            'media-library.queue_connection_name' => self::QUEUE_CONNECTION,
            'broadcasting.default' => 'null',
            'mail.default' => 'log',
        ]);

        $start = CarbonImmutable::now()->subDays(self::HISTORY_DAYS)->setTime(10, 0);

        try {
            $this->travelTo($start);

            $this->call(DatabaseSeeder::class);
            $this->seedExchangeRates($start);
            $operator = $this->seedOperator();

            foreach (self::BUSINESSES as $index => $business) {
                $this->seedBusiness($business, $index, $start, $operator);
            }
        } finally {
            Date::setTestNow();
            Auth::guard('web')->logout();
        }

        $now = CarbonImmutable::now();

        foreach (Organization::query()->get() as $organization) {
            $this->within($organization, function () use ($now): void {
                app(ExpireLayawaysAction::class)->handle($now);
                app(DetectOverdueAction::class)->handle($now);
            });
        }

        $this->runQueuedJobs();

        foreach (Organization::query()->get() as $organization) {
            app(UsageCalculator::class)->recalculate($organization);
        }

        $this->report();
    }

    private function runQueuedJobs(): void
    {
        $queues = DB::table('jobs')->distinct()->pluck('queue')->all();

        if ($queues === []) {
            return;
        }

        Artisan::call('queue:work', [
            'connection' => self::QUEUE_CONNECTION,
            '--queue' => implode(',', $queues),
            '--stop-when-empty' => true,
            '--tries' => 1,
        ]);
    }

    private function seedExchangeRates(CarbonImmutable $start): void
    {
        for ($day = 0; $day <= self::HISTORY_DAYS; $day++) {
            ExchangeRate::query()->create([
                'source' => RateSource::Bcv,
                'rate' => number_format(36.5 + $day * 0.12, 8, '.', ''),
                'published_at' => $start->addDays($day)->setTime(8, 0),
            ]);
        }
    }

    private function seedOperator(): PlatformUser
    {
        $operator = new PlatformUser;
        $operator->forceFill([
            'name' => 'Operador de Apartar',
            'email' => self::OPERATOR_EMAIL,
            'password' => self::PASSWORD,
            'must_change_password' => false,
        ])->save();

        return $operator;
    }

    /**
     * @param  array{name: string, slug: string, document: string, owner: array{0: string, 1: string}, member: array{0: string, 1: string}, approved: bool, categories: array<string, list<array{0: string, 1: int, 2: int, 3: int}>>}  $business
     */
    private function seedBusiness(array $business, int $index, CarbonImmutable $start, PlatformUser $operator): void
    {
        $this->travelTo($start->addMinutes($index * 30));

        $organization = app(RegisterOrganizationAction::class)->handle(new RegisterOrganizationData(
            organizationName: $business['name'],
            organizationSlug: $business['slug'],
            ownerName: $business['owner'][0],
            ownerEmail: $business['owner'][1],
            ownerPassword: self::PASSWORD,
        ));

        $organization->forceFill(['document' => $business['document'], 'storefront_enabled' => true])->save();
        $organization->refresh();

        $owner = $this->within($organization, function (): User {
            $owner = User::query()->where('role', OrganizationRole::Owner)->firstOrFail();
            $owner->forceFill(['email_verified_at' => CarbonImmutable::now()])->save();

            return $owner;
        });

        $this->upgradeToPro($organization, $owner, $business['approved'], $operator);

        $this->within($organization, function () use ($business): void {
            $member = new User;
            $member->forceFill([
                'name' => $business['member'][0],
                'email' => $business['member'][1],
                'password' => self::PASSWORD,
                'role' => OrganizationRole::Member,
                'must_change_password' => false,
                'email_verified_at' => CarbonImmutable::now(),
            ])->save();
        });

        $accounts = $this->within($organization, fn (): array => $this->seedPaymentAccounts($business, $owner));
        $products = $this->within($organization, fn (): array => $this->seedProducts($business['categories'], $owner));
        $catalog = $this->within($organization, fn (): Catalog => $this->seedCatalog($business['name'], $products, $owner));

        $this->seedLayaways($organization, $catalog, $products, $accounts, $owner, $start, $index);
    }

    private function upgradeToPro(Organization $organization, User $owner, bool $approved, PlatformUser $operator): void
    {
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();

        app(ChangePlanAction::class)->handle($organization, $pro, $owner);

        $this->within($organization, function () use ($owner, $approved, $operator): void {
            $invoice = SubscriptionInvoice::query()->latest('id')->firstOrFail();
            $amount = number_format($invoice->amount_cents / 100, 2, '.', '');

            $receipt = app(SubmitSubscriptionReceiptAction::class)->handle(new SubmitSubscriptionReceiptData(
                invoice: $invoice,
                method: PaymentMethod::Zelle,
                bank: null,
                reference: 'ZL'.random_int(100_000, 999_999),
                paidAt: CarbonImmutable::now(),
                declaredAmount: $amount,
                declaredCurrency: Currency::USD,
                file: $this->image('comprobante-plan', 'jpg'),
            ), $owner);

            if ($approved) {
                app(ApproveSubscriptionReceiptAction::class)->handle(
                    $receipt,
                    new ApproveSubscriptionReceiptData(settledAmount: $amount, settledCurrency: Currency::USD),
                    $operator,
                );
            }
        });
    }

    /**
     * @param  array{name: string, owner: array{0: string, 1: string}, document: string}  $business
     * @return array{pago_movil: PaymentAccount, zelle: PaymentAccount}
     */
    private function seedPaymentAccounts(array $business, User $owner): array
    {
        $upsert = app(UpsertPaymentAccountAction::class);

        return [
            'pago_movil' => $upsert->handle(new UpsertPaymentAccountData(
                method: PaymentMethod::PagoMovil,
                bank: 'Banco de Venezuela',
                holderName: $business['owner'][0],
                holderDocument: $business['document'],
                identifier: '04141234567',
                instructions: 'Envía la captura del pago móvil con la referencia completa.',
            ), $owner),
            'zelle' => $upsert->handle(new UpsertPaymentAccountData(
                method: PaymentMethod::Zelle,
                bank: null,
                holderName: $business['owner'][0],
                holderDocument: $business['document'],
                identifier: Str::slug($business['name']).'@zelle.test',
                instructions: 'Coloca tu nombre completo en el concepto.',
            ), $owner),
        ];
    }

    /**
     * @param  array<string, list<array{0: string, 1: int, 2: int, 3: int}>>  $categories
     * @return list<Product>
     */
    private function seedProducts(array $categories, User $owner): array
    {
        $products = [];

        foreach ($categories as $category => $items) {
            foreach ($items as [$name, $price, $downPayment, $stock]) {
                $product = app(UpsertProductAction::class)->handle(new ProductData(
                    name: $name,
                    sku: strtoupper(Str::substr(Str::slug($category, ''), 0, 3)).'-'.str_pad((string) (count($products) + 1), 3, '0', STR_PAD_LEFT),
                    slug: null,
                    description: $name.' disponible para apartar en cuotas cómodas.',
                    price: new Money($price),
                    compareAtPrice: count($products) % 3 === 0 ? new Money((int) round($price * 1.2)) : null,
                    downPayment: new Money($downPayment),
                    installmentsCount: 4,
                    frequencyDays: 7,
                    stock: $stock,
                    categoryName: $category,
                    tagNames: count($products) % 2 === 0 ? ['Nuevo'] : ['Oferta'],
                ), $owner);

                $this->attachImages($product, 2);

                $products[] = app(ChangeProductStatusAction::class)->publish($product, $owner);
            }
        }

        return $products;
    }

    private function attachImages(Product $product, int $count): void
    {
        for ($position = 1; $position <= $count; $position++) {
            $product
                ->addMedia($this->image(Str::slug($product->name).'-'.$position, 'jpg'))
                ->usingName($product->name.' — foto '.$position)
                ->withCustomProperties([TenantPathGenerator::ORGANIZATION_PROPERTY => app(TenantContext::class)->organizationId()])
                ->toMediaCollection(Product::IMAGES);
        }
    }

    /**
     * @param  list<Product>  $products
     */
    private function seedCatalog(string $business, array $products, User $owner): Catalog
    {
        $catalog = app(UpsertCatalogAction::class)->handle(new CatalogData(
            name: 'Catálogo de temporada',
            slug: Str::slug($business).'-temporada',
            description: 'Lo más pedido de '.$business.', con apartado en cuotas.',
            cover: null,
        ), $owner);

        app(SyncCatalogProductsAction::class)->handle($catalog, array_map(fn (Product $product): int => (int) $product->getKey(), $products), $owner);

        return app(PublishCatalogAction::class)->handle($catalog, new PublishCatalogData(isListed: true, expiresAt: null), $owner);
    }

    /**
     * @param  list<Product>  $products
     * @param  array{pago_movil: PaymentAccount, zelle: PaymentAccount}  $accounts
     */
    private function seedLayaways(Organization $organization, Catalog $catalog, array $products, array $accounts, User $owner, CarbonImmutable $start, int $index): void
    {
        $customers = array_slice([...self::CUSTOMERS, ...self::CUSTOMERS], $index * 3, 8);
        $plan = [
            ['day' => 1, 'flow' => 'completed'],
            ['day' => 2, 'flow' => 'overdue'],
            ['day' => 8, 'flow' => 'cancelled'],
            ['day' => 12, 'flow' => 'expired'],
            ['day' => 13, 'flow' => 'active_with_receipt'],
            ['day' => 15, 'flow' => 'active'],
            ['day' => 18, 'flow' => 'pending_with_receipt'],
            ['day' => 19, 'flow' => 'pending'],
        ];

        foreach ($plan as $position => $step) {
            $this->travelTo($start->addDays($step['day'])->setTime(11, $index * 10));

            $customer = $customers[$position];
            $layaway = $this->createLayaway($catalog, $products[$position % count($products)], $customer);
            $this->portalCodes[] = ['business' => $organization->name, 'document' => $customer[0], 'code' => $layaway->code];

            $this->within($organization, function () use ($step, $layaway, $accounts, $owner): void {
                match ($step['flow']) {
                    'completed' => $this->complete($layaway, $accounts, $owner),
                    'overdue', 'active' => $this->payDownPayment($layaway, $accounts['pago_movil'], $owner),
                    'active_with_receipt' => $this->activeWithPendingReceipt($layaway, $accounts, $owner),
                    'cancelled' => app(CancelLayawayAction::class)->handle($layaway, $owner, 'La clienta pidió anularlo antes de pagar la inicial.'),
                    'pending_with_receipt' => $this->submitReceipt($layaway, $accounts['pago_movil'], $layaway->down_payment_cents),
                    'expired', 'pending' => null,
                };
            });
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $customer
     */
    private function createLayaway(Catalog $catalog, Product $product, array $customer): Layaway
    {
        return app(CreateLayawayAction::class)->handle(new CreateLayawayData(
            catalog: $catalog,
            product: $product,
            quantity: 1,
            contact: new CustomerContactData(
                DocumentNumber::fromInput($customer[0]),
                $customer[1],
                PhoneNumber::fromInput($customer[2]),
                $customer[3],
            ),
            consent: TermsConsent::acceptedNowFrom(self::IP),
            idempotencyKey: (string) Str::uuid(),
        ));
    }

    /**
     * @param  array{pago_movil: PaymentAccount, zelle: PaymentAccount}  $accounts
     */
    private function complete(Layaway $layaway, array $accounts, User $owner): void
    {
        $this->payDownPayment($layaway, $accounts['pago_movil'], $owner);

        $layaway->refresh();

        app(RegisterManualPaymentAction::class)->handle($layaway, new ManualPaymentData(
            method: PaymentMethod::EfectivoUsd,
            paymentAccount: null,
            amount: $this->amount($layaway->remaining_cents),
            currency: Currency::USD,
            rateOverride: null,
            paidAt: CarbonImmutable::now(),
            note: 'Pagó el resto en efectivo en la tienda.',
            idempotencyKey: (string) Str::uuid(),
        ), $owner);

        app(MarkDeliveredAction::class)->handle($layaway->refresh(), $owner);
    }

    /**
     * @param  array{pago_movil: PaymentAccount, zelle: PaymentAccount}  $accounts
     */
    private function activeWithPendingReceipt(Layaway $layaway, array $accounts, User $owner): void
    {
        $this->payDownPayment($layaway, $accounts['pago_movil'], $owner);

        $this->travelTo(CarbonImmutable::now()->addDays(5));

        $layaway->refresh();
        $installment = intdiv($layaway->remaining_cents->cents, max(1, $layaway->installments_count));

        $this->submitReceipt($layaway, $accounts['zelle'], $installment);
    }

    private function payDownPayment(Layaway $layaway, PaymentAccount $account, User $owner): void
    {
        $receipt = $this->submitReceipt($layaway, $account, $layaway->down_payment_cents);

        app(ApproveReceiptAction::class)->handle($receipt, new ApproveReceiptData(
            settledAmount: $this->amount($layaway->down_payment_cents),
            settledCurrency: Currency::USD,
            rateOverride: null,
            note: null,
        ), $owner);
    }

    private function submitReceipt(Layaway $layaway, PaymentAccount $account, Money|int $cents): Receipt
    {
        return app(SubmitReceiptAction::class)->handle(new SubmitReceiptData(
            layaway: $layaway,
            paymentAccount: $account,
            method: $account->method,
            bank: match (true) {
                ! $account->method->requiresReference() => null,
                $account->method === PaymentMethod::Zelle => 'Bank of America',
                default => 'Banesco',
            },
            reference: (string) random_int(10_000_000, 99_999_999),
            paidAt: CarbonImmutable::now(),
            declaredAmount: $this->amount($cents),
            declaredCurrency: Currency::USD,
            note: null,
            file: $this->image('comprobante-'.$layaway->code, 'jpg'),
        ));
    }

    private function amount(Money|int $cents): string
    {
        $cents = $cents instanceof Money ? $cents->cents : $cents;

        return number_format($cents / 100, 2, '.', '');
    }

    private function image(string $name, string $extension): UploadedFile
    {
        $image = imagecreatetruecolor(800, 800);

        if ($image === false) {
            throw new RuntimeException('GD no pudo crear la imagen de ejemplo.');
        }

        $background = (int) imagecolorallocate($image, random_int(120, 230), random_int(120, 230), random_int(120, 230));
        imagefill($image, 0, 0, $background);

        for ($i = 0; $i < 12; $i++) {
            $color = (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            $x = random_int(0, 700);
            $y = random_int(0, 700);
            imagefilledellipse($image, $x, $y, random_int(60, 240), random_int(60, 240), $color);
        }

        $path = tempnam(sys_get_temp_dir(), 'apartar-demo-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de la imagen.');
        }

        imagejpeg($image, $path, 85);

        return new UploadedFile($path, $name.'.'.$extension, 'image/jpeg', null, true);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function within(Organization $organization, Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($organization, $callback);
    }

    private function travelTo(CarbonImmutable $moment): void
    {
        Date::setTestNow($moment);
    }

    private function report(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Escenario de prueba listo. Contraseña de todas las cuentas: '.self::PASSWORD);

        $rows = [['Plataforma', self::OPERATOR_EMAIL, '/plataforma/login']];

        foreach (self::BUSINESSES as $business) {
            $rows[] = [$business['name'].' (dueño)', $business['owner'][1], '/backoffice/login · vitrina /'.$business['slug']];
            $rows[] = [$business['name'].' (miembro)', $business['member'][1], '/backoffice/login'];
        }

        $this->command->table(['Cuenta', 'Correo', 'Entrada'], $rows);

        $statuses = DB::table('layaways')->whereIn('code', array_column($this->portalCodes, 'code'))->pluck('status', 'code');

        $this->command->info('Portal de clientes (/mi-cuenta/acceso): cédula + código del apartado.');
        $this->command->table(
            ['Negocio', 'Cédula', 'Código', 'Estado'],
            array_map(fn (array $row): array => [$row['business'], $row['document'], $row['code'], $statuses[$row['code']] ?? ''], $this->portalCodes),
        );
    }
}
