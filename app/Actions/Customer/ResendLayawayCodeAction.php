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

/**
 * El negocio reenvía el código de un apartado al cliente que lo perdió
 * (`REQ-IDN-008` SP-5, `REQ-BOF-041` RB-5, `T-065`), a su correo.
 *
 * Sin correo no hay a dónde mandarlo (SP-7): la pantalla ofrece copiarlo para
 * dictárselo por teléfono. El código solo sale hacia el correo **de la
 * ficha**, nunca a uno escrito en el momento: el código es media credencial
 * del portal.
 */
final class ResendLayawayCodeAction
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws AuthorizationException si quien reenvía no gestiona la cobranza de ese negocio
     * @throws NoEmailToResendException si la ficha no tiene correo
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
