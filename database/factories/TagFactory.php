<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Tag> */
    protected $model = Tag::class;

    /**
     * @return array<model-property<Tag>, mixed>
     */
    public function definition(): array
    {
        $name = 'etiqueta-'.Str::lower(Str::random(8));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
