<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Catalog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class TenantResolver
{
    /**
     * @throws ModelNotFoundException<Organization>
     */
    public function resolve(TenantSurface $surface, object|string|null $identifier = null): ?Organization
    {
        return match ($surface) {
            TenantSurface::Backoffice => $this->forTenantRecord($identifier, 'la sesión del backoffice'),
            TenantSurface::PublicCatalog => $this->forCatalogSlug(is_string($identifier) ? $identifier : ''),
            TenantSurface::Portal => $this->forTenantRecord($identifier, 'la ficha del portal'),
            TenantSurface::Storefront => $this->forOrganizationSlug(is_string($identifier) ? $identifier : ''),
            TenantSurface::Platform => null,
        };
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    public function forOrganizationId(int $organizationId): Organization
    {
        $organization = Organization::query()->find($organizationId);

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$organizationId]);
        }

        return $organization;
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    public function forOrganizationSlug(string $slug): Organization
    {
        $organization = Organization::query()->where('slug', $slug)->where('storefront_enabled', true)->first();

        if (! $organization instanceof Organization) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$slug]);
        }

        return $organization;
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    /**
     * @param  list<int>  $catalogIds
     * @return array<int, Organization>
     */
    public function organizationsOfCatalogs(array $catalogIds): array
    {
        if ($catalogIds === []) {
            return [];
        }

        $owners = DB::table('catalogs')->whereIn('id', $catalogIds)->pluck(TenantScope::COLUMN, 'id');
        $organizations = Organization::query()->whereIn('id', $owners->unique()->values()->all())->get()->keyBy('id');

        $result = [];

        foreach ($owners as $catalogId => $organizationId) {
            $organization = $organizations->get($organizationId);

            if ($organization instanceof Organization) {
                $result[(int) $catalogId] = $organization;
            }
        }

        return $result;
    }

    /**
     * @return list<Organization>
     */
    public function organizationsOfAccount(int $accountId): array
    {
        $ids = DB::table('customers')->where('account_id', $accountId)->whereNull('deleted_at')->distinct()->pluck(TenantScope::COLUMN)->all();

        return $ids === [] ? [] : Organization::query()->whereIn('id', $ids)->orderBy('id')->get()->all();
    }

    public function telemetryCatalog(string $slug): ?Catalog
    {
        $row = $slug === '' ? null : DB::table('catalogs')->where('slug', $slug)->first();

        return $row === null || ! is_numeric($row->{TenantScope::COLUMN} ?? null) ? null : (new Catalog)->newFromBuilder((array) $row);
    }

    public function publicCatalog(string $slug): Catalog
    {
        $row = $slug === '' ? null : DB::table('catalogs')->where('slug', $slug)->first();
        $organizationId = $row === null ? null : ($row->{TenantScope::COLUMN} ?? null);

        if (! is_numeric($organizationId)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, ['el catálogo público']);
        }

        $catalog = (new Catalog)->newFromBuilder((array) $row);
        $catalog->setRelation('organization', $this->forOrganizationId((int) $organizationId));

        return $catalog;
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    public function forLayawayCode(string $code): Organization
    {
        $organizationId = $code === '' ? null : DB::table('layaways')->where('code', $code)->value(TenantScope::COLUMN);

        if (! is_numeric($organizationId)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, ['el código de apartado']);
        }

        return $this->forOrganizationId((int) $organizationId);
    }

    /**
     * @return list<Organization>
     */
    public function organizationsWithCustomer(string $documentNumber, string $email): array
    {
        $ids = DB::table('customers')
            ->whereNull('deleted_at')
            ->where('document_number', $documentNumber)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->pluck(TenantScope::COLUMN)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? [] : Organization::query()->whereKey($ids)->orderBy('id')->get()->all();
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    private function forCatalogSlug(string $slug): Organization
    {
        return $this->publicCatalog($slug)->organization;
    }

    /**
     * @throws ModelNotFoundException<Organization>
     */
    private function forTenantRecord(?object $record, string $source): Organization
    {
        $organizationId = $record === null
            ? null
            : ($record->{TenantScope::COLUMN} ?? null);

        if (! is_numeric($organizationId)) {
            throw (new ModelNotFoundException)->setModel(Organization::class, [$source]);
        }

        return $this->forOrganizationId((int) $organizationId);
    }
}
