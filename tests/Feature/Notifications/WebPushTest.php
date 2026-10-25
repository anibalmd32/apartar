<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Account\LinkAccountAction;
use App\Actions\Chat\SendMessageAction;
use App\Domain\Account\LinkOrigin;
use App\Domain\Chat\MessageSenderType;
use App\Domain\Notification\NotifiableResolver;
use App\Domain\Notification\PushReportHandler;
use App\Events\MessageSent;
use App\Jobs\NotifyUnreadMessage;
use App\Jobs\PurgeStalePushSubscriptions;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\UnreadMessageNotification;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushMessage;
use Tests\TestCase;

final class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    private Organization $organization;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webpush.vapid.public_key' => 'BPublica', 'webpush.vapid.private_key' => 'privada']);
        Event::fake([MessageSent::class]);
        $this->organization = Organization::factory()->create(['name' => 'Ferretería Oriente']);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();
    }

    public function test_the_device_goes_to_the_account_or_the_record_and_a_repeated_endpoint_is_reassigned(): void
    {
        $this->actingAs($this->customer, 'customer')->postJson(route('portal.push.store'), $this->payload())->assertCreated();
        $this->assertSame([Customer::class], $this->owners());

        app('auth')->forgetGuards();
        $account = Account::factory()->create();
        $this->actingAs($account, 'account')->postJson(route('portal.push.store'), $this->payload())->assertCreated();

        $this->assertSame([Account::class], $this->owners());
        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }

    public function test_without_vapid_the_route_answers_503(): void
    {
        config(['webpush.vapid.public_key' => null]);

        $this->actingAs($this->customer, 'customer')->postJson(route('portal.push.store'), $this->payload())->assertStatus(503);
    }

    public function test_logging_out_keeps_the_subscription(): void
    {
        $this->actingAs($this->customer, 'customer')->postJson(route('portal.push.store'), $this->payload());
        $this->post(route('portal.logout'));

        $this->assertSame(1, DB::table('push_subscriptions')->count());
    }

    public function test_a_person_with_both_identities_is_notified_once(): void
    {
        $account = Account::factory()->create();
        $this->customer->updatePushSubscription('https://push.test/ficha', 'k', 't', 'aes128gcm');
        $account->updatePushSubscription(self::ENDPOINT, 'k', 't', 'aes128gcm');
        DB::table('customers')->where('id', $this->customer->getKey())->update(['account_id' => $account->getKey()]);

        $target = app(NotifiableResolver::class)->for($this->customer->fresh() ?? $this->customer);

        $this->assertInstanceOf(Account::class, $target);
        $this->assertSame([self::ENDPOINT], $target->routeNotificationForWebPush()->pluck('endpoint')->all());
        $this->assertSame([Account::class], $this->owners());
    }

    public function test_linking_the_account_moves_the_devices(): void
    {
        $account = Account::factory()->create();
        $this->customer->updatePushSubscription(self::ENDPOINT, 'k', 't', 'aes128gcm');

        $this->withinOrganization($this->organization, fn () => app(LinkAccountAction::class)->handle($this->customer, $account, LinkOrigin::VerifiedClaim));

        $this->assertSame([Account::class], $this->owners());
    }

    public function test_the_report_handler(): void
    {
        $subscription = $this->customer->updatePushSubscription(self::ENDPOINT, 'k', 't', 'aes128gcm');
        $handler = new PushReportHandler;
        $report = fn (int $status): MessageSentReport => new MessageSentReport(new PsrRequest('POST', self::ENDPOINT), new PsrResponse($status), $status < 300, 'motivo');

        $handler->handleReport($report(201), $subscription, new WebPushMessage);
        $this->assertNotNull(DB::table('push_subscriptions')->value('last_used_at'));

        $handler->handleReport($report(429), $subscription, new WebPushMessage);
        $this->assertSame(1, DB::table('push_subscriptions')->count());

        $handler->handleReport($report(410), $subscription, new WebPushMessage);
        $this->assertSame(0, DB::table('push_subscriptions')->count());
    }

    public function test_five_team_messages_queue_one_deferred_push_and_customer_messages_none(): void
    {
        Queue::fake();
        Cache::flush();

        $this->send(MessageSenderType::Customer);
        Queue::assertNothingPushed();

        foreach (range(1, 5) as $ignored) {
            $this->send(MessageSenderType::Team);
        }

        Queue::assertPushed(NotifyUnreadMessage::class, 1);
    }

    public function test_the_job_revalidates_and_names_the_business(): void
    {
        Notification::fake();
        Queue::fake();
        $this->customer->updatePushSubscription(self::ENDPOINT, 'k', 't', 'aes128gcm');
        $this->send(MessageSenderType::Team, str_repeat('Respuesta larga ', 20));

        $conversation = $this->withinOrganization($this->organization, fn (): Conversation => Conversation::query()->sole());
        $this->withoutOrganization();
        $job = new NotifyUnreadMessage((int) $this->organization->getKey(), (int) $conversation->getKey());

        app()->call([$job, 'handle']);

        Notification::assertSentTo($this->customer, UnreadMessageNotification::class, fn (UnreadMessageNotification $notification): bool => $notification->businessName === 'Ferretería Oriente'
            && mb_strlen($notification->excerpt) <= UnreadMessageNotification::EXCERPT_LENGTH);

        Notification::fake();
        $this->withinOrganization($this->organization, fn () => $conversation->markReadBy(MessageSenderType::Customer));
        $this->withoutOrganization();

        app()->call([$job, 'handle']);

        Notification::assertNothingSent();
    }

    public function test_stale_subscriptions_are_purged(): void
    {
        $this->customer->updatePushSubscription('https://push.test/vieja', 'k', 't', 'aes128gcm');
        $this->customer->updatePushSubscription(self::ENDPOINT, 'k', 't', 'aes128gcm');
        DB::table('push_subscriptions')->where('endpoint', 'https://push.test/vieja')->update(['created_at' => CarbonImmutable::now()->subDays(200)]);
        DB::table('push_subscriptions')->where('endpoint', self::ENDPOINT)->update(['created_at' => CarbonImmutable::now()->subDays(200), 'last_used_at' => CarbonImmutable::now()->subDays(10)]);

        $this->assertSame(1, (new PurgeStalePushSubscriptions)->handle());
        $this->assertSame([self::ENDPOINT], DB::table('push_subscriptions')->pluck('endpoint')->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'clave', 'auth' => 'token'], 'contentEncoding' => 'aes128gcm'];
    }

    /**
     * @return list<string>
     */
    private function owners(): array
    {
        return PushSubscription::query()->pluck('subscribable_type')->unique()->values()->all();
    }

    private function send(MessageSenderType $sender, string $body = 'Hola'): void
    {
        $senderId = $sender === MessageSenderType::Team
            ? (int) $this->withinOrganization($this->organization, fn (): int => (int) User::factory()->create()->getKey())
            : (int) $this->customer->getKey();
        $this->withoutOrganization();

        app(SendMessageAction::class)->handle($this->organization, $this->customer, $sender, $senderId, $body, (string) Str::uuid());
        $this->withoutOrganization();
    }
}
