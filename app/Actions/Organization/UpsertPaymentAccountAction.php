<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\RecordedReceipts;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class UpsertPaymentAccountAction
{
    use LogsSensitiveActivity;

    /**
     * @var list<string>
     */
    private const AUDITED_FIELDS = [
        'method',
        'bank',
        'holder_name',
        'holder_document',
        'identifier',
        'instructions',
        'is_active',
        'position',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly RecordedReceipts $receipts,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws PlanLimitReachedException
     */
    public function handle(UpsertPaymentAccountData $data, User $actor, ?PaymentAccount $account = null): PaymentAccount
    {
        Gate::forUser($actor)->authorize('managePaymentAccounts', $this->context->organization());

        return DB::transaction(function () use ($data, $account, $actor): PaymentAccount {
            if ($account instanceof PaymentAccount) {
                $locked = PaymentAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
                ModelState::adopt($account, $locked->getAttributes());
            }

            $wasActive = $account instanceof PaymentAccount && $account->is_active;
            $before = $this->auditedFieldsOf($account);

            if ($data->isActive && ! $wasActive) {
                $this->limits->assertCanCreate(UsageMetric::PaymentAccounts);
            }

            $attributes = [
                'method' => $data->method,
                'bank' => $data->bank,
                'holder_name' => $data->holderName,
                'holder_document' => $data->holderDocument,
                'identifier' => $data->identifier,
                'instructions' => $data->instructions,
                'is_active' => $data->isActive,

                'position' => $data->position
                    ?? ($account instanceof PaymentAccount ? $account->position : $this->nextPosition()),
            ];

            $isNew = ! $account instanceof PaymentAccount;

            if ($account instanceof PaymentAccount) {
                $account->forceFill($attributes)->save();
            } else {
                $account = PaymentAccount::query()->create($attributes);
            }

            $this->audit(
                $isNew ? AuditEvent::PaymentAccountCreated : AuditEvent::PaymentAccountUpdated,
                subject: $account,
                actor: $actor,
                before: $before,
                after: $this->auditedFieldsOf($account),
            );

            $this->limits->adjust(UsageMetric::PaymentAccounts, (int) $data->isActive - (int) $wasActive);

            return $account;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws PaymentAccountInUseException
     */
    public function delete(PaymentAccount $account, User $actor): void
    {
        Gate::forUser($actor)->authorize('managePaymentAccounts', $this->context->organization());

        $inUse = $this->receipts->existFor($account);

        DB::transaction(function () use ($account, $inUse, $actor): void {
            $locked = PaymentAccount::query()->whereKey($account->getKey())->lockForUpdate()->first();

            if (! $locked instanceof PaymentAccount) {
                return;
            }

            ModelState::adopt($account, $locked->getAttributes());
            $wasActive = $account->is_active;
            $before = $this->auditedFieldsOf($account);

            if ($inUse) {
                $account->forceFill(['is_active' => false])->save();
            } else {
                $account->delete();
            }

            $this->audit(
                AuditEvent::PaymentAccountRemoved,
                subject: $account,
                actor: $actor,
                before: $before,
                after: ['estado' => $inUse ? 'desactivada por tener comprobantes' : 'eliminada'],
            );

            if ($wasActive) {
                $this->limits->adjust(UsageMetric::PaymentAccounts, -1);
            }
        });

        if ($inUse) {
            throw PaymentAccountInUseException::deactivatedInstead($account);
        }
    }

    /**
     * @return list<string>
     */
    public function warningsFor(PaymentAccount $account): array
    {
        $warnings = [];

        if ($this->hasTwin($account)) {
            $warnings[] = sprintf(
                'Ya tenías otra cuenta de %s con ese %s. Se ha guardado igual: hay negocios con dos cuentas en el mismo banco.',
                $account->method->label(),
                mb_strtolower($account->method->identifierLabel()),
            );
        }

        if ($this->noneLeftActive()) {
            $warnings[] = 'Te has quedado sin ninguna cuenta de cobro activa. Tus catálogos publicados siguen publicados, '
                .'pero tus clientes no verán a dónde pagarte hasta que actives una.';
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedFieldsOf(?PaymentAccount $account): array
    {
        if (! $account instanceof PaymentAccount) {
            return [];
        }

        $fields = [];

        foreach (self::AUDITED_FIELDS as $field) {
            $fields[$field] = $account->getAttribute($field);
        }

        return $fields;
    }

    private function hasTwin(PaymentAccount $account): bool
    {
        $twin = UpsertPaymentAccountData::comparable($account->identifier);

        return PaymentAccount::query()
            ->where('method', $account->method)
            ->whereKeyNot($account->getKey())
            ->get()
            ->contains(fn (PaymentAccount $other): bool => UpsertPaymentAccountData::comparable($other->identifier) === $twin);
    }

    private function noneLeftActive(): bool
    {
        return ! PaymentAccount::query()->where('is_active', true)->exists();
    }

    private function nextPosition(): int
    {
        return (int) PaymentAccount::query()->max('position') + 1;
    }
}
