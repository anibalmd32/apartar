<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\ReservedSlugs;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RegisterOrganizationAction
{
    private const MAX_SLUG_VARIANTS = 50;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StartFreeSubscriptionAction $startFreeSubscription,
    ) {}

    /**
     * @return array{slug: string, available: bool, suggestion: string|null}
     */
    public function availability(string $requested): array
    {
        $slug = Str::slug($requested);

        if ($slug === '') {
            return ['slug' => '', 'available' => false, 'suggestion' => null];
        }

        $free = ! ReservedSlugs::contains($slug) && ! $this->slugExists($slug);

        return ['slug' => $slug, 'available' => $free, 'suggestion' => $free ? null : $this->firstFreeVariant($slug)];
    }

    /**
     * @throws SlugTakenException
     * @throws InvalidArgumentException
     */
    public function handle(RegisterOrganizationData $data): Organization
    {
        $slug = $this->resolveSlug($data);

        [$organization, $owner] = DB::transaction(
            fn (): array => $this->createEverything($data, $slug),
        );

        Auth::guard('web')->login($owner);

        return $organization;
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createEverything(RegisterOrganizationData $data, string $slug): array
    {
        $organization = $this->createOrganization($data, $slug);

        return $this->context->runFor($organization, function () use ($organization, $data): array {
            $owner = User::query()->create([
                'name' => $data->ownerName,
                'email' => $data->normalisedEmail(),
                'password' => $data->ownerPassword,
                'role' => OrganizationRole::Owner,
                'must_change_password' => false,
            ]);

            $this->startFreeSubscription->handle($organization);
            $this->seedDefaultSettings();

            return [$organization, $owner];
        });
    }

    /**
     * @throws SlugTakenException
     */
    private function createOrganization(RegisterOrganizationData $data, string $slug): Organization
    {
        try {
            return Organization::query()->create([
                'name' => trim($data->organizationName),
                'slug' => $slug,
                'document' => null,
                'status' => OrganizationStatus::Active,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw SlugTakenException::suggesting($slug, $this->firstFreeVariant($slug));
        }
    }

    private function seedDefaultSettings(): void
    {
        foreach (SettingKey::cases() as $key) {
            OrganizationSetting::query()->create([
                'key' => $key,
                'value' => $key->defaultValue(),
                'updated_by' => null,
            ]);
        }
    }

    /**
     * @throws SlugTakenException
     * @throws InvalidArgumentException
     */
    private function resolveSlug(RegisterOrganizationData $data): string
    {
        $slug = $data->preferredSlug();

        if ($slug === '') {
            throw new InvalidArgumentException(
                'El nombre del negocio no produce ningún enlace: hace falta escribir uno.',
            );
        }

        if (ReservedSlugs::contains($slug)) {
            throw SlugTakenException::reserved($slug, $this->firstFreeVariant($slug));
        }

        if ($this->slugExists($slug)) {
            throw SlugTakenException::suggesting($slug, $this->firstFreeVariant($slug));
        }

        return $slug;
    }

    private function firstFreeVariant(string $slug): string
    {
        for ($suffix = 2; $suffix <= self::MAX_SLUG_VARIANTS; $suffix++) {
            $candidate = $this->truncatedVariant($slug, (string) $suffix);

            if (! ReservedSlugs::contains($candidate) && ! $this->slugExists($candidate)) {
                return $candidate;
            }
        }

        return $this->truncatedVariant($slug, (string) random_int(1_000, 9_999));
    }

    private function truncatedVariant(string $slug, string $suffix): string
    {
        $room = 100 - (strlen($suffix) + 1);

        return substr($slug, 0, $room).'-'.$suffix;
    }

    private function slugExists(string $slug): bool
    {
        return Organization::query()->where('slug', $slug)->exists();
    }
}
