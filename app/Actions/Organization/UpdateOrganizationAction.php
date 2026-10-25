<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\PublishedCatalogs;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ReservedSlugs;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class UpdateOrganizationAction
{
    use LogsSensitiveActivity;

    private const LOGO_DIRECTORY = 'logos';

    /**
     * @var list<string>
     */
    private const AUDITED_IDENTITY = [
        'name',
        'slug',
        'document',
        'phone',
        'timezone',
        'brand_color',
        'storefront_enabled',
        'logo_path',
        'tagline',
        'description',
        'address',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly PublishedCatalogs $publishedCatalogs,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws SlugLockedException
     * @throws SlugTakenException
     */
    public function handle(UpdateOrganizationData $data, User $actor): Organization
    {
        $organization = $this->context->organization();

        Gate::forUser($actor)->authorize('manageBranding', $organization);

        $slug = $this->resolveSlug($organization, $data->slug);

        $attributes = [
            'name' => $data->name,
            'slug' => $slug,
            'document' => $data->document,
            'phone' => $data->phone,
            'timezone' => $data->timezone,
            'brand_color' => $data->normalisedBrandColor(),
            'storefront_enabled' => $data->storefrontEnabled,
        ] + $data->profile;

        if ($data->logo instanceof UploadedFile) {
            $attributes['logo_path'] = $this->storeLogo($organization, $data->logo);
        }

        $before = $this->auditedIdentityOf($organization);

        try {
            DB::transaction(function () use ($organization, $attributes, $before, $actor): void {
                $organization->forceFill($attributes)->save();

                $this->audit(
                    AuditEvent::IdentityUpdated,
                    subject: $organization,
                    actor: $actor,
                    before: $before,
                    after: $this->auditedIdentityOf($organization),
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw SlugTakenException::suggesting($slug, $slug.'-2');
        }

        return $organization;
    }

    public function brandOf(Organization $organization): OrganizationBrand
    {
        return OrganizationBrand::of($organization, $this->limits->hasFeature(PlanFeature::Branding));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedIdentityOf(Organization $organization): array
    {
        $identity = [];

        foreach (self::AUDITED_IDENTITY as $attribute) {
            $identity[$attribute] = $organization->getAttribute($attribute);
        }

        return $identity;
    }

    /**
     * @throws SlugLockedException
     * @throws SlugTakenException
     */
    private function resolveSlug(Organization $organization, string $requested): string
    {
        $slug = Str::slug($requested);

        if ($slug === '' || $slug === $organization->slug) {
            return $organization->slug;
        }

        if ($this->publishedCatalogs->exist()) {
            throw SlugLockedException::hasPublishedCatalogs($organization->slug);
        }

        if (ReservedSlugs::contains($slug)) {
            throw SlugTakenException::reserved($slug, $slug.'-2');
        }

        if (Organization::query()->where('slug', $slug)->whereKeyNot($organization->getKey())->exists()) {
            throw SlugTakenException::suggesting($slug, $slug.'-2');
        }

        return $slug;
    }

    private function storeLogo(Organization $organization, UploadedFile $logo): string
    {
        $disk = Storage::disk(OrganizationBrand::LOGO_DISK);

        $path = $logo->store(self::LOGO_DIRECTORY.'/'.$organization->getKey(), OrganizationBrand::LOGO_DISK);

        $previous = $organization->logo_path;

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            $disk->delete($previous);
        }

        return (string) $path;
    }
}
