<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\ChangeTeamMemberRoleAction;
use App\Actions\Organization\DisableTeamMemberAction;
use App\Actions\Organization\EnableTeamMemberAction;
use App\Actions\Organization\InviteTeamMemberAction;
use App\Actions\Organization\UpdateOrganizationAction;
use App\Actions\Organization\UpdateOrganizationData;
use App\Actions\Organization\UpsertPaymentAccountAction;
use App\Actions\Organization\UpsertPaymentAccountData;
use App\Actions\Settings\UpdateSettingsAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Auditing\AuditProperties;
use App\Domain\Auditing\AuditTrail;
use App\Domain\Payment\PaymentAccountInUseException;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\RecordedReceipts;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\AuditEntry;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class AuditedActionsTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(OrganizationBrand::LOGO_DISK);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $this->actingAsOrganization($this->organization);

        Subscription::factory()->forPlan(Plan::factory()->create())->create();
    }

    public function test_inviting_and_revoking_are_audited(): void
    {
        $owner = $this->owner();
        $invite = app(InviteTeamMemberAction::class);

        $member = $invite->handle('Ana Pérez', 'ana@eltornillo.com', OrganizationRole::Member, $owner);

        $invited = $this->entryOf(AuditEvent::TeamMemberInvited);

        $this->assertStringContainsString('Ana Pérez', $invited->description);
        $this->assertSame('ana@eltornillo.com', $this->changed($invited)['email']['after']);

        $invite->revoke($member, $owner);

        $revoked = $this->entryOf(AuditEvent::TeamInvitationRevoked);

        $this->assertStringContainsString('Ana Pérez', $revoked->description);
        $this->assertSame('revocada', $this->changed($revoked)['status']['after']);
    }

    public function test_the_invitation_token_never_reaches_the_properties(): void
    {
        $owner = $this->owner();

        app(InviteTeamMemberAction::class)->handle('Ana Pérez', 'ana@eltornillo.com', OrganizationRole::Member, $owner);

        $properties = json_encode($this->entryOf(AuditEvent::TeamMemberInvited)->properties?->toArray() ?? []);

        $this->assertIsString($properties);
        $this->assertStringNotContainsString('invitation_token', $properties);

        $hash = User::query()->where('email', 'ana@eltornillo.com')->value('invitation_token_hash');

        $this->assertIsString($hash);
        $this->assertStringNotContainsString($hash, $properties);
    }

    public function test_disabling_and_enabling_a_member_are_audited(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create(['name' => 'Luis Mora']);
        $this->recalculateUsage();

        app(DisableTeamMemberAction::class)->handle($member, $owner);

        $disabled = $this->entryOf(AuditEvent::TeamMemberDisabled);

        $this->assertStringContainsString('Luis Mora', $disabled->description);
        $this->assertSame('desactivado', $this->changed($disabled)['access']['after']);

        app(EnableTeamMemberAction::class)->handle($member->refresh(), $owner);

        $this->assertSame('activo', $this->changed($this->entryOf(AuditEvent::TeamMemberEnabled))['access']['after']);
    }

    public function test_a_role_change_records_both_roles(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create(['name' => 'Luis Mora']);

        app(ChangeTeamMemberRoleAction::class)->handle($member, OrganizationRole::Owner, $owner);

        $changed = $this->changed($this->entryOf(AuditEvent::TeamMemberRoleChanged));

        $this->assertSame('member', $changed['role']['before']);
        $this->assertSame('owner', $changed['role']['after']);
    }

    public function test_a_role_change_that_changes_nothing_writes_no_entry(): void
    {
        $owner = $this->owner();
        $member = User::factory()->create();

        app(ChangeTeamMemberRoleAction::class)->handle($member, OrganizationRole::Member, $owner);

        $this->assertSame(0, AuditEntry::query()->where('event', AuditEvent::TeamMemberRoleChanged->value)->count());
    }

    public function test_updating_settings_is_audited_with_the_previous_value(): void
    {
        $owner = $this->owner();

        foreach (SettingKey::cases() as $key) {
            OrganizationSetting::query()->create(['key' => $key, 'value' => $key->defaultValue(), 'updated_by' => null]);
        }

        app(UpdateSettingsAction::class)->handle(['grace_period_days' => 7], $owner);

        $changed = $this->changed($this->entryOf(AuditEvent::SettingsUpdated));

        $this->assertSame(3, $changed['grace_period_days']['before']);
        $this->assertSame(7, $changed['grace_period_days']['after']);

        $this->assertSame(
            (int) $owner->getKey(),
            (int) OrganizationSetting::query()->where('key', SettingKey::GracePeriodDays)->value('updated_by'),
        );
    }

    public function test_changing_the_identity_is_audited(): void
    {
        $owner = $this->owner();

        app(UpdateOrganizationAction::class)->handle(new UpdateOrganizationData(
            name: 'Ferretería El Tornillo',
            slug: 'el-tornillo',
            document: null,
            phone: null,
            timezone: 'America/Caracas',
            brandColor: null,
            storefrontEnabled: false,
        ), $owner);

        $changed = $this->changed($this->entryOf(AuditEvent::IdentityUpdated));

        $this->assertSame('ferreteria-el-tornillo', $changed['slug']['before']);
        $this->assertSame('el-tornillo', $changed['slug']['after']);
    }

    public function test_payment_account_lifecycle_is_audited(): void
    {
        $owner = $this->owner();
        $upsert = app(UpsertPaymentAccountAction::class);

        $account = $upsert->handle($this->accountData(), $owner);

        $created = $this->changed($this->entryOf(AuditEvent::PaymentAccountCreated));
        $this->assertSame('0414-1234567', $created['identifier']['after']);

        $upsert->handle($this->accountData(identifier: '0424-7654321'), $owner, $account);

        $updated = $this->changed($this->entryOf(AuditEvent::PaymentAccountUpdated));
        $this->assertSame('0414-1234567', $updated['identifier']['before']);
        $this->assertSame('0424-7654321', $updated['identifier']['after']);

        $upsert->delete($account->refresh(), $owner);

        $this->assertSame('eliminada', $this->changed($this->entryOf(AuditEvent::PaymentAccountRemoved))['estado']['after']);
    }

    public function test_a_degraded_delete_records_that_it_was_deactivated(): void
    {
        $owner = $this->owner();
        $upsert = app(UpsertPaymentAccountAction::class);

        $account = $upsert->handle($this->accountData(), $owner);

        $this->app->bind(RecordedReceipts::class, fn (): RecordedReceipts => new class extends RecordedReceipts
        {
            public function existFor(PaymentAccount $account): bool
            {
                return true;
            }
        });

        $upsert = app(UpsertPaymentAccountAction::class);

        try {
            $upsert->delete($account, $owner);
        } catch (PaymentAccountInUseException) {
        }

        $this->assertStringContainsString(
            'desactivada',
            (string) $this->changed($this->entryOf(AuditEvent::PaymentAccountRemoved))['estado']['after'],
        );
    }

    public function test_a_failing_audit_does_not_roll_back_the_operation(): void
    {
        $owner = $this->owner();

        $this->app->bind(AuditTrail::class, fn (): AuditTrail => new class extends AuditTrail
        {
            /**
             * @param  array<string, mixed>  $properties
             */
            public function record(
                AuditEvent $event,
                string $description,
                ?Model $subject,
                ?Model $causer,
                array $properties,
            ): void {
                throw new RuntimeException('activity_log no está disponible');
            }
        });

        app(InviteTeamMemberAction::class)->handle('Ana Pérez', 'ana@eltornillo.com', OrganizationRole::Member, $owner);

        $this->assertDatabaseHas('users', ['email' => 'ana@eltornillo.com']);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    private function entryOf(AuditEvent $event): AuditEntry
    {
        /** @var AuditEntry $entry */
        $entry = AuditEntry::query()->audited()->ofEvent($event)->latest('id')->firstOrFail();

        return $entry;
    }

    /**
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function changed(AuditEntry $entry): array
    {
        /** @var array<string, array{before: mixed, after: mixed}> $changed */
        $changed = $entry->properties?->toArray()['changed'] ?? [];

        $this->assertNotSame([], $changed, 'La entrada llegó sin detalle: '.$entry->description);
        $this->assertNotContains(AuditProperties::REDACTED, array_keys($changed));

        return $changed;
    }

    private function accountData(string $identifier = '0414-1234567'): UpsertPaymentAccountData
    {
        return new UpsertPaymentAccountData(
            method: PaymentMethod::PagoMovil,
            bank: 'Banesco',
            holderName: 'Carlos Rojas',
            holderDocument: 'V-12345678',
            identifier: $identifier,
            instructions: null,
        );
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }
}
