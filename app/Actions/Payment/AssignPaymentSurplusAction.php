<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\StaffReason;
use App\Domain\Payment\InvalidSurplusDestinationException;
use App\Domain\Payment\SurplusDestination;
use App\Models\Layaway;
use App\Models\Payment;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AssignPaymentSurplusAction
{
    use LogsSensitiveActivity;

    /**
     * @throws AuthorizationException
     * @throws InvalidSurplusDestinationException
     */
    public function handle(Payment $payment, SurplusDestination $destination, User $actor, string $reason): Payment
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $layaway);

        return DB::transaction(function () use ($payment, $destination, $actor, $layaway, $reason): Payment {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true)) {
                throw InvalidSurplusDestinationException::layawayClosed($locked->status);
            }

            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedPayment->hasSurplus()) {
                throw InvalidSurplusDestinationException::noSurplus();
            }

            $member = $this->memberOf($destination, (int) $lockedPayment->organization_id);
            $motive = new StaffReason($reason);

            $before = $this->detailOf($lockedPayment);

            $lockedPayment->forceFill([
                'surplus_destination_type' => $destination->type,
                'surplus_user_id' => $member?->getKey(),
                'surplus_team_member_name' => $member?->name,
                'surplus_note' => $destination->noteText(),
                'surplus_assigned_by' => $actor->getKey(),
                'surplus_assigned_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit(
                AuditEvent::PaymentSurplusAssigned,
                subject: $lockedPayment,
                actor: $actor,
                before: $before,
                after: [
                    ...$this->detailOf($lockedPayment),
                    'reason' => $motive->text,
                ],
            );

            return $lockedPayment;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws InvalidSurplusDestinationException
     */
    public function remove(Payment $payment, User $actor, string $reason): Payment
    {
        $layaway = Layaway::query()->findOrFail($payment->layaway_id);
        Gate::forUser($actor)->authorize('manage', $layaway);

        return DB::transaction(function () use ($payment, $actor, $layaway, $reason): Payment {
            /** @var Layaway $locked */
            $locked = Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [LayawayStatus::Cancelled, LayawayStatus::Expired], true)) {
                throw InvalidSurplusDestinationException::layawayClosed($locked->status);
            }

            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedPayment->hasSurplus()) {
                throw InvalidSurplusDestinationException::noSurplus();
            }

            $motive = new StaffReason($reason);

            $before = $this->detailOf($lockedPayment);

            $lockedPayment->forceFill([
                'surplus_destination_type' => null,
                'surplus_user_id' => null,
                'surplus_team_member_name' => null,
                'surplus_note' => null,
                'surplus_assigned_by' => $actor->getKey(),
                'surplus_assigned_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit(
                AuditEvent::PaymentSurplusAssigned,
                subject: $lockedPayment,
                actor: $actor,
                before: $before,
                after: [
                    ...$this->detailOf($lockedPayment),
                    'reason' => $motive->text,
                ],
            );

            return $lockedPayment;
        });
    }

    /**
     * @throws InvalidSurplusDestinationException
     */
    private function memberOf(SurplusDestination $destination, int $organizationId): ?User
    {
        if ($destination->type->requiresUser() && ! $destination->teamMember instanceof User) {
            throw InvalidSurplusDestinationException::unknownMember();
        }

        if (! $destination->type->requiresUser() && $destination->teamMember instanceof User) {
            throw InvalidSurplusDestinationException::unknownMember();
        }

        if ($destination->type->requiresNote() && $destination->noteText() === null) {
            throw InvalidSurplusDestinationException::missingNote();
        }

        if ($destination->noteText() !== null && mb_strlen((string) $destination->noteText()) > SurplusDestination::NOTE_MAX) {
            throw InvalidSurplusDestinationException::longNote();
        }

        $member = $destination->teamMember;

        if (! $member instanceof User) {
            return null;
        }

        if ((int) $member->organization_id !== $organizationId) {
            throw InvalidSurplusDestinationException::foreignMember();
        }

        if ($member->disabled_at !== null) {
            throw InvalidSurplusDestinationException::inactiveMember();
        }

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(Payment $payment): array
    {
        return [
            'surplus_destination_type' => $payment->surplus_destination_type?->value,
            'surplus_team_member' => $payment->surplus_team_member_name,
            'surplus_note' => $payment->surplus_note,
            'unallocated_cents' => $payment->unallocated_cents->cents,
        ];
    }
}
