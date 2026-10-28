<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Actions\Mail\ImportInboundMessageAction;
use App\Models\InboundMail;
use App\Models\InboundMailAttachment;
use App\Models\MailThread;
use App\Models\SentMail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ImportInboundMessageActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const API = 'https://mailtrap.io/api/inbound/inboxes/15/messages/';

    private const FILE_URL = 'https://inbound-files.mailtrap.example/att-1?X-Amz-Signature=abc';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(InboundMailAttachment::DISK);
        config(['services.mailtrap.api_token' => 'token-de-prueba', 'services.mailtrap.inbox_id' => null]);
    }

    public function test_it_fetches_the_message_and_keeps_it_as_is_with_its_attachment(): void
    {
        Http::fake([self::API.'msg-1' => Http::response($this->message('msg-1')), self::FILE_URL => Http::response('%PDF-1.4 contenido')]);

        $mail = $this->import('msg-1');

        $this->assertInstanceOf(InboundMail::class, $mail);
        $this->assertSame('demo.eth@gmail.com', $mail->from_address);
        $this->assertSame('Ivan Djuric', $mail->from_name);
        $this->assertSame(['ventas@apartar.app'], $mail->to);
        $this->assertSame(['socio@example.com'], $mail->cc);
        $this->assertSame('Pregunta por el plan', $mail->subject);
        $this->assertSame('2C613DD4@gmail.com', $mail->message_id);
        $this->assertSame('<p>Hola <script>x()</script></p>', $mail->html_body);
        $this->assertSame('Hola', $mail->text_body);
        $this->assertSame('1.0', $mail->headers['mime-version']);
        $this->assertSame('msg-1', $mail->payload['id']);
        $this->assertSame('2026-10-28 08:17:31', $mail->received_at->setTimezone('UTC')->format('Y-m-d H:i:s'));
        $this->assertNull($mail->read_at);

        $thread = $mail->thread;
        $this->assertSame('thr-1', $thread->mailtrap_thread_id);
        $this->assertSame('demo.eth@gmail.com', $thread->counterpart);
        $this->assertSame([1, 1, 'Hola'], [$thread->messages_count, $thread->unread_count, $thread->snippet]);

        $attachment = $mail->attachments()->sole();
        $this->assertSame(['factura.pdf', 'application/pdf', 2048], [$attachment->filename, $attachment->content_type, $attachment->size]);
        $this->assertNotNull($attachment->path);
        Storage::disk(InboundMailAttachment::DISK)->assertExists((string) $attachment->path);
        $this->assertSame('%PDF-1.4 contenido', Storage::disk(InboundMailAttachment::DISK)->get((string) $attachment->path));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'msg-1' && $request->hasHeader('Authorization', 'Bearer token-de-prueba'));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::FILE_URL && ! $request->hasHeader('Authorization'));
    }

    public function test_importing_twice_does_not_call_mailtrap_again(): void
    {
        Http::fake([self::API.'*' => Http::response($this->message('msg-1')), self::FILE_URL => Http::response('pdf')]);

        $first = $this->import('msg-1');
        $second = $this->import('msg-1');

        $this->assertTrue($first?->is($second));
        $this->assertSame(1, InboundMail::query()->count());
        Http::assertSentCount(2);
    }

    public function test_a_failed_attachment_download_is_retried_with_fresh_links(): void
    {
        Http::fakeSequence(self::FILE_URL)->push('caído', 500)->push('pdf');
        Http::fake([self::API.'*' => Http::response($this->message('msg-1'))]);

        try {
            $this->import('msg-1');
            $this->fail('Debió avisar del adjunto que no se pudo bajar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('adjunto', $exception->getMessage());
        }

        $this->assertNull(InboundMailAttachment::query()->sole()->path);

        $this->import('msg-1');

        $this->assertSame(1, InboundMail::query()->count());
        $this->assertNotNull(InboundMailAttachment::query()->sole()->path);
    }

    public function test_a_reply_joins_the_thread_of_the_mail_it_answers(): void
    {
        $thread = MailThread::factory()->create();
        SentMail::factory()->create(['message_id' => 'nuestro-1@apartar.app', 'mail_thread_id' => $thread->getKey()]);
        Http::fake([self::API.'*' => Http::response($this->message('msg-2', ['in_reply_to' => '<nuestro-1@apartar.app>', 'thread_id' => null, 'attachments' => []]))]);

        $mail = $this->import('msg-2');

        $this->assertSame($thread->getKey(), $mail?->mail_thread_id);
        $this->assertSame(2, $thread->refresh()->messages_count);
    }

    public function test_a_reply_to_an_automatic_mail_opens_a_thread_that_includes_it(): void
    {
        $sent = SentMail::factory()->create(['message_id' => 'auto-1@apartar.app']);
        Http::fake([self::API.'*' => Http::response($this->message('msg-3', ['references' => ['<auto-1@apartar.app>'], 'attachments' => []]))]);

        $mail = $this->import('msg-3');

        $this->assertSame($mail?->mail_thread_id, $sent->refresh()->mail_thread_id);
    }

    public function test_messages_of_another_inbox_are_ignored(): void
    {
        config(['services.mailtrap.inbox_id' => 99]);
        Http::fake();

        $this->assertNull($this->import('msg-1'));
        Http::assertNothingSent();
    }

    public function test_without_a_token_it_refuses_to_call_mailtrap(): void
    {
        config(['services.mailtrap.api_token' => null]);
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->import('msg-1');
    }

    private function import(string $id): ?InboundMail
    {
        return app(ImportInboundMessageAction::class)->handle(15, $id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function message(string $id, array $overrides = []): array
    {
        return [
            'id' => $id,
            'inbox_id' => 15,
            'from' => 'Ivan Djuric <demo.eth@gmail.com>',
            'to' => ['ventas@apartar.app'],
            'cc' => ['Socio <socio@example.com>'],
            'bcc' => [],
            'reply_to' => null,
            'subject' => 'Pregunta por el plan',
            'rfc_message_id' => '<2C613DD4@gmail.com>',
            'in_reply_to' => null,
            'references' => [],
            'headers' => ['mime-version' => '1.0'],
            'size' => 3665,
            'received_at' => '2026-10-28T08:17:31.878Z',
            'thread_id' => 'thr-1',
            'html_body' => '<p>Hola <script>x()</script></p>',
            'text_body' => 'Hola',
            'attachments' => [[
                'attachment_id' => 'att-1',
                'filename' => '../factura.pdf',
                'content_type' => 'application/pdf',
                'content_disposition' => 'attachment',
                'content_id' => null,
                'size' => 2048,
                'download_url' => self::FILE_URL,
            ]],
            ...$overrides,
        ];
    }
}
