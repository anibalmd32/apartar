<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Customer\NoEmailToResendException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\User;
use App\Notifications\LayawayCodeNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

final class ResendLayawayCodeAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws AuthorizationException
     * @throws NoEmailToResendException
     */
    public function handle(Layaway $layaway, User $actor): string
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        $email = Customer::query()->whereKey($layaway->customer_id)->value('email');

        if (! is_string($email) || $email === '') {
            throw new NoEmailToResendException;
        }

        Notification::route('mail', $email)->notify(new LayawayCodeNotification(
            code: $layaway->code,
            businessName: $this->context->organization()->name,
            productName: $layaway->product_name_snapshot,
            portalUrl: route('portal.access'),
        ));

        return $email;
    }
}
