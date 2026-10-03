<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Customer\LoginLinkToken;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Customer;
use App\Models\CustomerLoginToken;
use App\Models\Organization;
use App\Notifications\CustomerLoginLinkNotification;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Envía el enlace de acceso al portal por correo (`REQ-IDN-003`).
 *
 * Es la vía **secundaria** (RB-1): solo sirve a quien dejó correo. Pide
 * documento **y** correo, y los dos tienen que ser de la misma ficha (RB-2).
 *
 * ## Un enlace por negocio
 *
 * El documento es único por organización, así que la misma persona puede
 * tener ficha en varios negocios con el mismo correo. Se envía **un enlace por
 * cada uno**, acotado a la suya (RB-3, SP-5): una sesión acotada nunca mezcla
 * negocios. Las organizaciones se descubren con
 * `TenantResolver::organizationsWithCustomer()` y, en cada una, la ficha se
 * vuelve a buscar con su tenant fijado.
 *
 * ## Lo que no dice
 *
 * Nada. Coincidan los datos con tres fichas o con ninguna (SP-3), y tenga la
 * ficha correo o no (SP-4), quien llama responde lo mismo: el número que
 * devuelve es para el log y para los tests, nunca para la persona.
 *
 * Emitir un enlace **caduca los anteriores** sin canjear de esa ficha (RB-5),
 * y el correo sale después del commit: un enlace que no llegó a guardarse no
 * se envía.
 */
final class IssueLoginLinkAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @return int cuántos enlaces se enviaron; nunca se le dice a la persona
     */
    public function handle(DocumentNumber $document, string $email, string $requestedIp): int
    {
        $sent = 0;

        foreach ($this->resolver->organizationsWithCustomer($document->value, $email) as $organization) {
            $sent += $this->context->runFor($organization, fn (): int => $this->issueIn($organization, $document, $email, $requestedIp));
        }

        return $sent;
    }

    private function issueIn(Organization $organization, DocumentNumber $document, string $email, string $requestedIp): int
    {
        $customer = Customer::query()
            ->where('document_number', $document->value)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        if (! $customer instanceof Customer || $customer->email === null) {
            return 0;
        }

        $link = DB::transaction(function () use ($organization, $customer, $requestedIp): LoginLinkToken {
            $now = CarbonImmutable::now();

            // RB-5 · los anteriores sin canjear dejan de servir ya.
            CustomerLoginToken::query()
                ->where('customer_id', $customer->getKey())
                ->whereNull('consumed_at')
                ->where('expires_at', '>', $now)
                ->update(['expires_at' => $now]);

            $link = LoginLinkToken::issueFor($organization);

            $token = new CustomerLoginToken;
            $token->forceFill([
                'customer_id' => $customer->getKey(),
                'token_hash' => $link->tokenHash,
                'expires_at' => $link->expiresAt,
                'requested_ip' => $requestedIp,
            ])->save();

            return $link;
        });

        Notification::route('mail', $customer->email)->notify(new CustomerLoginLinkNotification(
            url: $link->url(),
            businessName: $organization->name,
            expiresAt: $link->expiresAt->setTimezone(new DateTimeZone($organization->timezone))->locale('es')->isoFormat('h:mm a'),
        ));

        return 1;
    }
}
