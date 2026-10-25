<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Tenancy\TenantContext;
use App\Models\Tag;
use App\Models\User;
use App\Support\TaxonomySlug;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpsertTagAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(string $name, ?string $slug, User $actor, ?Tag $tag = null): Tag
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return DB::transaction(function () use ($name, $slug, $tag): Tag {
            if (! $tag instanceof Tag) {
                $requested = $slug === null ? $name : TaxonomySlug::normalize($slug);

                return Tag::query()->create([
                    'name' => $name,
                    'slug' => TaxonomySlug::from($requested, $this->slugIsTaken(), 80),
                ]);
            }

            $attributes = ['name' => $name];
            $normalized = $slug === null ? null : TaxonomySlug::normalize($slug);

            if ($normalized !== null && $normalized !== '' && $normalized !== $tag->slug) {
                $attributes['slug'] = TaxonomySlug::from($normalized, $this->slugIsTaken($tag), 80);
            }

            $tag->forceFill($attributes)->save();

            return $tag;
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(Tag $tag, User $actor): void
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $tag->delete();
    }

    /**
     * @return Closure(string): bool
     */
    private function slugIsTaken(?Tag $except = null): Closure
    {
        $exceptId = $except?->getKey();

        return function (string $slug) use ($exceptId): bool {
            $query = Tag::query()->where('slug', $slug);

            if ($exceptId !== null) {
                $query->whereKeyNot($exceptId);
            }

            return $query->exists();
        };
    }
}
