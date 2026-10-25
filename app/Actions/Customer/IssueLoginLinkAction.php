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

final class IssueLoginLinkAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
    ) {}

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
