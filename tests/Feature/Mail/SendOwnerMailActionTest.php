<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Actions\Mail\SendOwnerMailAction;
use App\Domain\Mail\ComposeMode;
use App\Domain\Mail\MailCategory;
use App\Domain\Mail\MailStatus;
use App\Models\InboundMail;
use App\Models\InboundMailAttachment;
use App\Models\MailThread;
use App\Models\PlatformActivity;
use App\Models\PlatformUser;
use App\Models\SentMail;
use App\Support\Auditing\PlatformAudit;
use App\Support\Mail\MailThreads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReadsSentMail;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SendOwnerMailActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReadsSentMail;
    use ReusesMigratedDatabase;

    public function test_a_reply_goes_out_from_info_with_threading_headers_and_stays_in_the_thread(): void
    {
        $operator = $this->operator();
        $source = $this->inbound(['references' => ['<raiz@gmail.com>'], 'message_id' => 'pregunta@gmail.com', 'reply_to' => 'ana@empresa.com']);

        $sent = $this->send($operator, ComposeMode::Reply, $source, ['ana@empresa.com'], [], 'Re: Precio', '<p>Hola <strong>Ana</strong></p><script>x()</script>');

        $email = $this->lastEmail();
        $this->assertSame('info@apartar.app', $email->getFrom()[0]->getAddress());
        $this->assertSame('ana@empresa.com', $email->getTo()[0]->getAddress());
        $this->assertSame('<pregunta@gmail.com>', $email->getHeaders()->get('In-Reply-To')?->getBodyAsString());
        $this->assertSame('<raiz@gmail.com> <pregunta@gmail.com>', $email->getHeaders()->get('References')?->getBodyAsString());
        $this->assertSame('<'.$sent->uuid.'@apartar.app>', $email->getHeaders()->get('Message-ID')?->getBodyAsString());
        $this->assertStringContainsString('<strong>Ana</strong>', (string) $email->getHtmlBody());
        $this->assertStringNotContainsString('<script>', (string) $email->getHtmlBody());
        $this->assertStringContainsString('<blockquote', (string) $email->getHtmlBody());
        $this->assertStringContainsString("Hola Ana\n\nEl ", (string) $email->getTextBody());
        $this->assertStringContainsString('> ¿Cuánto cuesta el plan?', (string) $email->getTextBody());

        $this->assertSame(MailCategory::MANUAL, $sent->category);
        $this->assertSame(MailStatus::Sent, $sent->status);
        $this->assertSame($operator->getKey(), $sent->platform_user_id);
        $this->assertSame($source->mail_thread_id, $sent->mail_thread_id);

        $thread = $source->thread()->firstOrFail();
        $this->assertSame([2, 0, 'Hola Ana'], [$thread->messages_count, $thread->unread_count, $thread->snippet]);
        $this->assertNotNull($source->refresh()->read_at);

        $audit = PlatformActivity::query()->where('action', PlatformAudit::MAIL_SENT)->sole();
        $this->assertSame($operator->getKey(), $audit->platform_user_id);
        $this->assertSame($sent->uuid, $audit->properties['sent_mail']);
    }

    public function test_a_new_mail_opens_a_thread_that_the_answer_will_join(): void
    {
        $sent = $this->send($this->operator(), ComposeMode::New, null, ['rosa@example.com'], ['luis@example.com'], 'Bienvenida', '<p>Hola Rosa</p>');

        $thread = MailThread::query()->findOrFail($sent->mail_thread_id);
        $this->assertSame(['Bienvenida', 'rosa@example.com', 1], [$thread->subject, $thread->counterpart, $thread->messages_count]);
        $this->assertSame(['luis@example.com'], $sent->cc);
        $this->assertNull($this->lastEmail()->getHeaders()->get('In-Reply-To'));

        $joined = app(MailThreads::class)->resolve(MailThreads::ids('<'.$sent->message_id.'>', []), null, 'Re: Bienvenida', 'rosa@example.com', CarbonImmutable::now());
        $this->assertTrue($joined->is($thread));
    }

    public function test_a_forward_carries_the_stored_attachments_without_reply_headers(): void
    {
        Storage::fake(InboundMailAttachment::DISK);
        $source = $this->inbound();
        Storage::disk(InboundMailAttachment::DISK)->put('inbound/1/factura.pdf', '%PDF');
        $source->attachments()->create(['mailtrap_attachment_id' => 'a1', 'filename' => 'factura.pdf', 'content_type' => 'application/pdf', 'path' => 'inbound/1/factura.pdf']);
        $source->attachments()->create(['mailtrap_attachment_id' => 'a2', 'filename' => 'perdido.pdf', 'content_type' => 'application/pdf']);

        $this->send($this->operator(), ComposeMode::Forward, $source, ['contador@example.com'], [], 'Rv: Precio', '<p>Mira esto</p>');

        $email = $this->lastEmail();
        $this->assertNull($email->getHeaders()->get('In-Reply-To'));
        $this->assertSame(['factura.pdf'], array_map(fn ($part): ?string => $part->getFilename(), $email->getAttachments()));
    }

    public function test_it_refuses_an_empty_body_or_no_recipients_without_sending(): void
    {
        foreach ([[['a@example.com'], '<p> </p>'], [[], '<p>Hola</p>']] as [$to, $body]) {
            try {
                $this->send($this->operator(), ComposeMode::New, null, $to, [], 'Asunto', $body);
                $this->fail('Debió rechazarse.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertCount(0, $this->sentEmails());
        $this->assertSame(0, SentMail::query()->count());
    }

    public function test_a_transport_failure_leaves_the_mail_marked_as_failed(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        app('mail.manager')->forgetMailers();

        try {
            $this->send($this->operator(), ComposeMode::New, null, ['a@example.com'], [], 'Asunto', '<p>Hola</p>');
            $this->fail('Debió fallar el envío.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No se pudo enviar', $exception->getMessage());
        }

        $this->assertSame(MailStatus::Failed, SentMail::query()->sole()->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function inbound(array $attributes = []): InboundMail
    {
        $thread = MailThread::factory()->create(['unread_count' => 1, 'messages_count' => 1]);

        return InboundMail::factory()->create([
            'mail_thread_id' => $thread->getKey(),
            'from_address' => 'ana@gmail.com',
            'from_name' => 'Ana',
            'subject' => 'Precio',
            'text_body' => '¿Cuánto cuesta el plan?',
            ...$attributes,
        ]);
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    private function send(PlatformUser $operator, ComposeMode $mode, ?InboundMail $source, array $to, array $cc, string $subject, string $html): SentMail
    {
        return app(SendOwnerMailAction::class)->handle($operator, $mode, $source, $to, $cc, $subject, $html)->refresh();
    }
}
