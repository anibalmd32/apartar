<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Notification\CustomerNotices;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Events\Layaway\LayawayTransitioned;
use App\Models\Layaway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class SendLayawayNotifications implements ShouldQueue
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly CustomerNotices $notices,
    ) {}

    public function handle(LayawayTransitioned $event): void
    {
        try {
            $organization = $this->resolver->forOrganizationId($event->organizationId());
        } catch (ModelNotFoundException) {
            return;
        }

        $this->context->runFor($organization, function () use ($event): void {
            $layaway = Layaway::query()->find($event->layawayId());

            if (! $layaway instanceof Layaway) {
                return;
            }

            match (true) {
                $event->to() === LayawayStatus::Active && $event->from() === LayawayStatus::PendingDownPayment => $this->notices->activated($layaway),
                $event->to() === LayawayStatus::Completed => $this->notices->completed($layaway),
                $event->to() === LayawayStatus::Cancelled => $this->notices->cancelled($layaway),
                $event->to() === LayawayStatus::Expired => $this->notices->expired($layaway),
                default => null,
            };
        });
    }
}
