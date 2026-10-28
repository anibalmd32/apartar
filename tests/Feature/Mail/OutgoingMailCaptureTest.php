<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Domain\Mail\MailStatus;
use App\Models\SentMail;
use App\Notifications\LayawayCodeNotification;
use App\Notifications\SupportAccessGrantedNotification;
use App\Support\Mail\OutgoingMailRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReadsSentMail;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class OutgoingMailCaptureTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReadsSentMail;
    use ReusesMigratedDatabase;

    public function test_every_sent_notification_is_stored_with_its_bodies_and_mailtrap_headers(): void
    {
        Notification::route('mail', 'Cliente@Gmail.com')->notify(new LayawayCodeNotification('AP-7K3M9QRT', 'Moda Valentina', 'Vestido lino', 'https://apartar.app/mi-cuenta'));

        $mail = SentMail::query()->sole();

        $this->assertSame(['cliente@gmail.com'], $mail->to);
        $this->assertSame('gmail.com', $mail->recipient_domain);
        $this->assertSame('LayawayCodeNotification', $mail->category);
        $this->assertSame(MailStatus::Sent, $mail->status);
        $this->assertNotNull($mail->sent_at);
        $this->assertSame(config('mail.from.address'), $mail->from_address);
        $this->assertStringContainsString('AP-7K3M9QRT', (string) $mail->html_body);
        $this->assertStringContainsString('AP-7K3M9QRT', (string) $mail->text_body);
        $this->assertNotSame('', $mail->subject);
        $this->assertSame($mail->uuid.'@apartar.app', $mail->message_id);
        $this->assertNull($mail->target_organization_id);

        $sent = $this->lastEmail();
        $headers = $sent->getHeaders();

        $this->assertSame(['apartar_mail_id' => $mail->uuid], json_decode((string) $headers->get('X-MT-Custom-Variables')?->getBodyAsString(), true));
        $this->assertSame('LayawayCodeNotification', $headers->get('X-MT-Category')?->getBodyAsString());
        $this->assertSame('<'.$mail->uuid.'@apartar.app>', $headers->get('Message-ID')?->getBodyAsString());
        $this->assertFalse($headers->has(OutgoingMailRecorder::UUID_HEADER));
        $this->assertSame($mail->uuid, OutgoingMailRecorder::uuidOf($sent));
    }

    public function test_it_keeps_the_organization_of_the_notified_team_member(): void
    {
        [$organization, $owner] = $this->businessWithOwner();

        $owner->notify(new SupportAccessGrantedNotification('Rosa', 'Revisar un pago', 'mañana'));

        $mail = SentMail::query()->where('category', 'SupportAccessGrantedNotification')->sole();

        $this->assertSame($organization->getKey(), $mail->target_organization_id);
        $this->assertSame([strtolower($owner->email)], $mail->to);
    }

    public function test_a_broken_recorder_never_stops_the_mail(): void
    {
        SentMail::creating(fn (): never => throw new RuntimeException('base de datos caída'));

        Notification::route('mail', 'cliente@example.com')->notify(new LayawayCodeNotification('AP-7K3M9QRT', 'Moda', 'Vestido', 'https://apartar.app'));

        $this->assertCount(1, $this->sentEmails());
        $this->assertSame(0, SentMail::query()->count());
    }

    public function test_a_mail_stuck_in_sending_counts_as_failed_after_the_timeout(): void
    {
        $now = CarbonImmutable::parse('2026-10-28 12:00');
        $this->travelTo($now);
        $fresh = SentMail::factory()->create(['status' => MailStatus::Sending, 'created_at' => $now->subMinutes(5)]);
        $stuck = SentMail::factory()->create(['status' => MailStatus::Sending, 'created_at' => $now->subMinutes(MailStatus::PENDING_TIMEOUT_MINUTES + 1)]);

        $this->assertSame(MailStatus::Sending, $fresh->effectiveStatus());
        $this->assertSame(MailStatus::Failed, $stuck->effectiveStatus());

        $failed = SentMail::query();
        SentMail::whereEffectiveStatus($failed, MailStatus::Failed);
        $sending = SentMail::query();
        SentMail::whereEffectiveStatus($sending, MailStatus::Sending);

        $this->assertSame([$stuck->getKey()], $failed->pluck('id')->all());
        $this->assertSame([$fresh->getKey()], $sending->pluck('id')->all());
    }
}
