<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogNotPublishableException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class PublishCatalogAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CreationSuspendedException
     * @throws CatalogNotPublishableException
     */
    public function handle(Catalog $catalog, PublishCatalogData $data, User $actor): Catalog
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $this->assertOrganizationIsActive();

        $missing = $this->missingToPublish($catalog, $data);

        if ($missing !== []) {
            throw CatalogNotPublishableException::of($catalog, $missing);
        }

        return $this->apply($catalog, $data, $actor);
    }

    /**
     * @return array<string, string>
     */
    public function blockersFor(Catalog $catalog): array
    {
        $expiresAt = $catalog->expires_at;

        $missing = $this->missingToPublish($catalog, new PublishCatalogData(
            isListed: $catalog->is_listed,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::instance($expiresAt),
        ));

        if (! $catalog->visibility()->countsTowardPlanLimit() && $this->limits->remaining(UsageMetric::Catalogs) === 0) {
            $missing['plan'] = 'Tu plan no admite otro catálogo publicado. '.UsageMetric::Catalogs->freeUpHint().' O cambia de plan en Suscripción.';
        }

        return $missing;
    }

    /**
     * @throws CreationSuspendedException
     */
    private function assertOrganizationIsActive(): void
    {
        if ($this->context->organization()->status === OrganizationStatus::Active) {
            return;
        }

        throw CreationSuspendedException::whileCreating(UsageMetric::Catalogs);
    }

    /**
     * @return array<string, string>
     */
    private function missingToPublish(Catalog $catalog, PublishCatalogData $data): array
    {
        $missing = [];

        if (! $catalog->products()->published()->exists()) {
            $missing['products'] = 'Añade al menos un producto publicado. Los borradores y los programados pueden '
                .'estar dentro, pero no se muestran: el enlace abriría un catálogo vacío.';
        }

        if (! PaymentAccount::query()->where('is_active', true)->exists()) {
            $missing['payment_accounts'] = 'Activa al menos una cuenta de cobro: es a dónde le dirás a tu cliente '
                .'que pague, y sin ella el catálogo no puede cerrar una venta.';
        }

        if ($data->expiresAt !== null && $data->expiresAt->isPast()) {
            $missing['expires_at'] = 'La fecha de caducidad ya pasó: el enlace diría que la campaña terminó desde '
                .'el primer momento. Muévela al futuro, o déjala vacía para que el catálogo no caduque.';
        }

        if (! $this->ownerEmailIsVerified()) {
            $missing['owner_email'] = 'Falta verificar el correo del dueño del negocio: es la dirección con la que '
                .'se recupera la cuenta y a la que llegan los avisos. Abre el enlace que te enviamos al '
                .'registrarte, o pide uno nuevo.';
        }

        return $missing;
    }

    private function ownerEmailIsVerified(): bool
    {
        return User::query()
            ->where('role', OrganizationRole::Owner)
            ->whereNull('disabled_at')
            ->whereNotNull('email_verified_at')
            ->exists();
    }

    private function apply(Catalog $catalog, PublishCatalogData $data, User $actor): Catalog
    {
        return DB::transaction(function () use ($catalog, $data, $actor): Catalog {
            $this->limits->lockCounter(UsageMetric::Catalogs);

            ModelState::adopt($catalog, Catalog::query()->whereKey($catalog->getKey())->lockForUpdate()->firstOrFail()->getAttributes());

            $this->usage->recountLocked(UsageMetric::Catalogs);

            $before = $this->snapshotOf($catalog);
            $counted = $catalog->visibility()->countsTowardPlanLimit();

            if (! $counted) {
                $this->limits->assertCanCreate(UsageMetric::Catalogs);
            }
            $catalog->editedBy($actor)->forceFill([
                'is_listed' => $data->isListed,
                'expires_at' => $data->expiresAt,
                'published_at' => $catalog->published_at ?? now(),
                'withdrawn_at' => null,
            ])->save();

            $after = $this->snapshotOf($catalog);

            if ($after === $before) {
                return $catalog;
            }

            $this->limits->adjust(
                UsageMetric::Catalogs,
                (int) $catalog->visibility()->countsTowardPlanLimit() - (int) $counted,
            );

            $this->audit(
                AuditEvent::CatalogPublished,
                subject: $catalog,
                actor: $actor,
                before: $before,
                after: $after,
            );

            if ($before['expires_at'] !== $after['expires_at']) {
                $this->audit(
                    AuditEvent::CatalogExpirationScheduled,
                    subject: $catalog,
                    actor: $actor,
                    before: ['expires_at' => $before['expires_at']],
                    after: ['expires_at' => $after['expires_at']],
                );
            }

            return $catalog;
        });
    }

    /**
     * @return array{visibility: string, is_listed: bool, published_at: string|null, expires_at: string|null}
     */
    private function snapshotOf(Catalog $catalog): array
    {
        return [
            'visibility' => $catalog->visibility()->value,
            'is_listed' => $catalog->is_listed,
            'published_at' => $catalog->published_at?->toIso8601String(),
            'expires_at' => $catalog->expires_at?->toIso8601String(),
        ];
    }
}
