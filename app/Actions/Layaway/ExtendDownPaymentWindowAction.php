<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Domain\Layaway\DownPaymentExtension;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Layaway;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ExtendDownPaymentWindowAction
{
    public const EXTENSION_HOURS = 24;

    public function handle(Layaway $layaway, CarbonImmutable $rejectedAt): DownPaymentExtension
    {
        return DB::transaction(function () use ($layaway, $rejectedAt): DownPaymentExtension {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            $outcome = $this->decide($locked, $rejectedAt);

            if ($outcome->wasGranted()) {
                $locked->forceFill([
                    'expires_at' => $rejectedAt->addHours(self::EXTENSION_HOURS),
                    'extended_at' => $rejectedAt,
                ])->save();
            }

            $fresh = $locked->fresh() ?? throw new LogicException('El apartado desapareció a mitad de su prórroga.');
            ModelState::adopt($layaway, $fresh->getAttributes());

            return $outcome;
        });
    }

    private function decide(Layaway $layaway, CarbonImmutable $rejectedAt): DownPaymentExtension
    {
        if ($layaway->status !== LayawayStatus::PendingDownPayment) {
            return DownPaymentExtension::NotAwaitingDownPayment;
        }

        if ($layaway->extended_at !== null) {
            return DownPaymentExtension::AlreadyExtended;
        }

        if ($layaway->expires_at === null || $layaway->expires_at->greaterThanOrEqualTo($rejectedAt)) {
            return DownPaymentExtension::WindowStillOpen;
        }

        return DownPaymentExtension::Granted;
    }
}
