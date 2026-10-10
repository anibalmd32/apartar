<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Catalog\ProductStatus;
use App\Domain\Exchange\CurrentRate;
use App\Domain\Exchange\RateSnapshot;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\PlanLimitRejection;
use App\Models\Product;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los avisos del panel (`REQ-BOF-044` RB-4): las condiciones con las que el
 * negocio está perdiendo ventas o dinero sin darse cuenta, cada una con una
 * acción que solo él puede tomar. **La parte más valiosa del panel**, y por
 * eso cada aviso sale solo en su condición (el gate pide un test por cada uno
 * en su condición y fuera de ella), y sin avisos la sección no existe (SP-6).
 *
 * Los siete. `plan_limit_blocking_sales` exige **evidencia de una venta
 * perdida** —un apartado rechazado por el límite en los últimos 7 días
 * (RB-5)—, que anota `CreateLayawayAction` en `plan_limit_rejections` desde
 * `T-067`. La factura vencida es, desde `T-069`, una factura emitida y sin
 * pagar con el vencimiento pasado, y su fecha es la de esa factura.
 */
final class DashboardWarningsQuery
{
    /** Cuántos catálogos vacíos se nombran: un aviso por cada uno, sin inundar. */
    public const MAX_EMPTY_CATALOGS = 3;

    /** Hasta cuándo un apartado rechazado por el límite cuenta como venta perdida (RB-5). */
    public const LOST_SALE_DAYS = 7;

    public function __construct(private readonly CurrentRate $rate) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forOrganization(Organization $organization): array
    {
        $warnings = [];

        // `REQ-ORG-007` SP-4 · sin cuenta de cobro activa nadie sabe adónde pagar.
        if (PaymentAccount::query()->where('is_active', true)->doesntExist()) {
            $warnings[] = ['kind' => 'no_active_payment_account'];
        }

        // `REQ-CAT-021` SP-4 · publicado, vigente y sin ningún producto a la venta.
        foreach (Catalog::query()
            ->whereNotNull('published_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now()))
            ->whereDoesntHave('products', fn (Builder $products) => $products->where('products.status', ProductStatus::Published))
            ->orderBy('name')
            ->limit(self::MAX_EMPTY_CATALOGS)
            ->pluck('slug') as $slug) {
            $warnings[] = ['kind' => 'published_catalog_empty', 'catalogSlug' => $slug];
        }

        // `REQ-CAT-007` RB-6 · publicados que no están en ningún catálogo: nadie los ve.
        $orphans = Product::query()->published()->whereDoesntHave('catalogs')->count();

        if ($orphans > 0) {
            $warnings[] = ['kind' => 'product_published_in_no_catalog', 'count' => $orphans];
        }

        // `REQ-FX-005` RB-6 · la tasa con la que se cobra en bolívares tiene más de 24 h.
        $snapshot = $this->rate->snapshot();

        if ($snapshot instanceof RateSnapshot && $snapshot->isStale()) {
            $warnings[] = ['kind' => 'stale_exchange_rate', 'ageHours' => (int) $snapshot->publishedAt->diffInHours(CarbonImmutable::now())];
        }

        // `REQ-SUB-007` RB-2, `T-069` · una factura de la suscripción emitida,
        // sin pagar y con el vencimiento pasado **en el día del negocio**: la
        // más antigua, con su vencimiento, y el aviso lleva a pagarla. Una con
        // el comprobante en revisión no avisa —el negocio ya pagó y espera a
        // la plataforma— (RB-10: el aviso de pago va primero).
        $overdue = SubscriptionInvoice::query()
            ->where('status', InvoiceStatus::Pending)
            ->where('due_date', '<', CarbonImmutable::now($organization->timezone)->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();

        if ($overdue instanceof SubscriptionInvoice) {
            $warnings[] = ['kind' => 'invoice_overdue', 'dueDate' => $overdue->due_date->toDateString()];
        }

        // `REQ-SUB-004` SP-2, RB-5 (`T-067`) · un límite del plan está
        // rechazando apartados: **solo con evidencia**, un rechazo anotado en
        // los últimos 7 días, y nunca por estar cerca del techo. Uno por
        // métrica, con ella, para que el aviso diga cuál.
        foreach (PlanLimitRejection::query()
            ->where('occurred_at', '>=', CarbonImmutable::now()->subDays(self::LOST_SALE_DAYS))
            ->distinct()
            ->orderBy('metric')
            ->pluck('metric') as $metric) {
            $warnings[] = ['kind' => 'plan_limit_blocking_sales', 'metric' => $metric instanceof UsageMetric ? $metric->value : (string) $metric];
        }

        // `REQ-ORG-001` RB-5 · el correo del dueño sin verificar: ni recupera la cuenta ni recibe avisos.
        if (User::query()->where('role', OrganizationRole::Owner)->whereNull('email_verified_at')->exists()) {
            $warnings[] = ['kind' => 'email_not_verified'];
        }

        return $warnings;
    }
}
