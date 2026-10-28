<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Catalog\ImageSet;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AttachProductImagesAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UsageCalculator $usage,
        private readonly LimitEnforcer $limits,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws \App\Domain\Subscription\PlanLimitReachedException
     */
    public function handle(Product $product, array $files): void
    {
        $position = $product->getMedia(Product::IMAGES)->count();

        try {
            foreach ($files as $file) {
                $position++;

                DB::transaction(function () use ($product, $file, $position): void {
                    $this->usage->recountLocked(UsageMetric::StorageMb);
                    $this->limits->assertCanCreate(UsageMetric::StorageMb, $this->usage->megabytesAdded((int) $file->getSize()));
                    $this->attach($product, $file, $position);
                    $this->usage->recountLocked(UsageMetric::StorageMb);
                });
            }
        } finally {
            $this->measureStorage();
        }
    }

    public function measureStorage(): void
    {
        try {
            $this->usage->refresh($this->context->organization(), UsageMetric::StorageMb);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function attach(Product $product, UploadedFile $file, int $position): void
    {
        $product
            ->addMedia($file)
            ->usingName(ImageSet::defaultAlt($product->name, $position))
            ->withCustomProperties([
                TenantPathGenerator::ORGANIZATION_PROPERTY => $this->context->organizationId(),
            ])
            ->toMediaCollection(Product::IMAGES);
    }
}
