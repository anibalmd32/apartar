<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Chat\MessageSenderType;
use App\Domain\Customer\CannotMergeCustomerException;
use App\Domain\Customer\MergeSummary;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerLoginToken;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Models\Message;
use App\Models\Receipt;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MergeCustomersAction
{
    use LogsSensitiveActivity;

    public function preview(Customer $survivor, Customer $absorbed): MergeSummary
    {
        return new MergeSummary(
            layaways: Layaway::query()->where('customer_id', $absorbed->getKey())->count(),
            receipts: Receipt::query()->where('customer_id', $absorbed->getKey())->count(),
            loginTokens: CustomerLoginToken::query()->where('customer_id', $absorbed->getKey())->count(),
            accountMoves: $absorbed->account_id !== null && $survivor->account_id === null,
            messages: Message::query()->whereIn('conversation_id', Conversation::query()->where('customer_id', $absorbed->getKey())->select('id'))->count(),
            telemetryEvents: TelemetryEvent::query()->where('customer_id', $absorbed->getKey())->count(),
        );
    }

    /**
     * @throws AuthorizationException
     * @throws CannotMergeCustomerException
     */
    public function handle(Customer $survivor, Customer $absorbed, User $actor): MergeSummary
    {
        if ((int) $survivor->getKey() === (int) $absorbed->getKey()) {
            throw CannotMergeCustomerException::sameRecord();
        }

        if ((int) $survivor->organization_id !== (int) $absorbed->organization_id) {
            throw CannotMergeCustomerException::otherOrganization();
        }

        Gate::forUser($actor)->authorize('merge', [Customer::class, $survivor, $absorbed]);

        return DB::transaction(function () use ($survivor, $absorbed, $actor): MergeSummary {
            Layaway::query()->where('customer_id', $absorbed->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
            CustomerLoginToken::query()->where('customer_id', $absorbed->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
            Conversation::query()->where('customer_id', $absorbed->getKey())->lockForUpdate()->pluck('id');

            $locked = Customer::query()
                ->whereKey([$survivor->getKey(), $absorbed->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $keeper = $locked->get($survivor->getKey());
            $gone = $locked->get($absorbed->getKey());

            if (! $keeper instanceof Customer || ! $gone instanceof Customer) {
                throw CannotMergeCustomerException::gone();
            }

            if ($keeper->account_id !== null && $gone->account_id !== null && (int) $keeper->account_id !== (int) $gone->account_id) {
                throw CannotMergeCustomerException::bothLinked();
            }

            $summary = $this->preview($keeper, $gone);
            $before = ['absorbed' => [
                'id' => (int) $gone->getKey(),
                'full_name' => $gone->full_name,
                'document_number' => $gone->document_number,
                'phone' => $gone->phone,
                'email' => $gone->email,
                'terms_accepted_at' => $gone->terms_accepted_at?->toIso8601String(),
                'created_at' => $gone->created_at?->toIso8601String(),
            ]];

            Layaway::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);
            Receipt::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);
            CustomerLoginToken::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);
            $this->mergeConversations($keeper, $gone);
            TelemetryEvent::query()->where('customer_id', $gone->getKey())->update(['customer_id' => $keeper->getKey()]);

            if ($summary->accountMoves) {
                $account = $gone->account_id;
                $gone->forceFill(['account_id' => null])->save();
                $keeper->forceFill(['account_id' => $account])->save();
            }

            CustomerMerge::query()->where('survivor_customer_id', $gone->getKey())->update(['survivor_customer_id' => $keeper->getKey()]);

            $merge = new CustomerMerge;
            $merge->forceFill([
                'absorbed_customer_id' => $gone->getKey(),
                'absorbed_document_number' => $gone->document_number,
                'absorbed_remember_token' => $gone->getRememberToken(),
                'survivor_customer_id' => $keeper->getKey(),
                'merged_by' => $actor->getKey(),
            ])->save();

            $gone->forceDelete();

            $this->audit(
                AuditEvent::CustomerMerged,
                subject: $keeper,
                actor: $actor,
                before: $before,
                after: ['moved' => $summary->toArray()],
            );

            return $summary;
        }, self::ATTEMPTS);
    }

    private function mergeConversations(Customer $keeper, Customer $gone): void
    {
        $from = Conversation::query()->where('customer_id', $gone->getKey())->lockForUpdate()->first();

        if (! $from instanceof Conversation) {
            return;
        }

        Message::query()
            ->where('conversation_id', $from->getKey())
            ->where('sender_type', MessageSenderType::Customer)
            ->update(['sender_id' => $keeper->getKey()]);

        $into = Conversation::query()->where('customer_id', $keeper->getKey())->lockForUpdate()->first();

        if (! $into instanceof Conversation) {
            $from->forceFill(['customer_id' => $keeper->getKey()])->save();

            return;
        }

        Message::query()->where('conversation_id', $from->getKey())->update(['conversation_id' => $into->getKey()]);

        $into->forceFill([
            'customer_unread_count' => $into->customer_unread_count + $from->customer_unread_count,
            'admin_unread_count' => $into->admin_unread_count + $from->admin_unread_count,
            'last_message_at' => max($into->last_message_at, $from->last_message_at),
        ])->save();

        $from->delete();
    }

    private const ATTEMPTS = 3;
}
