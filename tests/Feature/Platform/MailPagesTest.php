<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Mail\MailStatus;
use App\Models\InboundMail;
use App\Models\InboundMailAttachment;
use App\Models\MailThread;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReadsSentMail;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MailPagesTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReadsSentMail;
    use ReusesMigratedDatabase;

    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'métricas' => ['/plataforma/correos'];
        yield 'enviados' => ['/plataforma/correos/enviados'];
        yield 'bandeja' => ['/plataforma/correos/bandeja'];
        yield 'redactar' => ['/plataforma/correos/redactar'];
    }

    #[DataProvider('pages')]
    public function test_only_platform_operators_get_in(string $url): void
    {
        $this->get($url)->assertRedirect('/plataforma/login');

        [, $owner] = $this->businessWithOwner();
        $this->actingAs($owner)->get($url)->assertRedirect('/plataforma/login');

        $this->asOperator($this->operator(['disabled_at' => now()]));
        $this->get($url)->assertRedirect('/plataforma/login');
    }

    public function test_the_metrics_page_reads_the_requested_range(): void
    {
        $this->asOperator();

        $this->get('/plataforma/correos?desde=2026-10-01&hasta=2026-10-07')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/mail/Metrics')
            ->where('filters.desde', '2026-10-01')
            ->where('filters.hasta', '2026-10-07')
            ->has('metrics.daily', 7)
            ->has('metrics.rates.delivery'));
    }

    public function test_the_sent_list_filters_and_the_detail_shows_the_timeline(): void
    {
        $bounced = SentMail::factory()->to('ana@gmail.com')->create(['subject' => 'Tu código', 'status' => MailStatus::Bounced]);
        SentMail::factory()->to('luis@example.com')->create(['subject' => 'Otra cosa']);
        (new SentMailEvent)->forceFill(['event_id' => 'e1', 'sent_mail_id' => $bounced->getKey(), 'type' => 'bounce', 'occurred_at' => now(), 'reason' => 'Mailbox full', 'payload' => [], 'created_at' => now()])->save();
        $this->asOperator();

        $this->get('/plataforma/correos/enviados?estado=bounced&q=ana@')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/mail/Sent')
            ->has('mails', 1)
            ->where('mails.0.uuid', $bounced->uuid)
            ->where('mails.0.statusLabel', 'Rebotado')
            ->where('filters.estado', 'bounced'));

        $this->get('/plataforma/correos/enviados/'.$bounced->uuid)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/mail/SentShow')
            ->where('mail.subject', 'Tu código')
            ->where('mail.html', '<p>Hola</p>')
            ->where('mail.events.0.label', 'Rebote')
            ->where('mail.events.0.reason', 'Mailbox full'));

        $this->get('/plataforma/correos/enviados/no-es-un-uuid')->assertNotFound();
    }

    public function test_opening_a_thread_marks_it_read_and_it_can_be_marked_unread_again(): void
    {
        $thread = MailThread::factory()->create(['subject' => 'Duda', 'unread_count' => 1, 'messages_count' => 1]);
        $mail = InboundMail::factory()->create(['mail_thread_id' => $thread->getKey(), 'subject' => 'Duda']);
        $this->asOperator();

        $this->get('/plataforma/correos/bandeja?filtro=no-leidos')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/mail/Inbox')
            ->where('threads.0.id', $thread->getKey())
            ->where('unreadThreads', 1));

        $this->get('/plataforma/correos/bandeja/'.$thread->getKey())->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/mail/Thread')
            ->where('messages.0.kind', 'inbound')
            ->where('messages.0.unread', true));

        $this->assertNotNull($mail->refresh()->read_at);
        $this->assertSame(0, $thread->refresh()->unread_count);

        $this->post('/plataforma/correos/bandeja/'.$thread->getKey().'/no-leido')->assertRedirect('/plataforma/correos/bandeja');

        $this->assertNull($mail->refresh()->read_at);
        $this->assertSame(1, $thread->refresh()->unread_count);
    }

    public function test_attachments_are_downloaded_only_by_operators_and_never_rendered_inline(): void
    {
        Storage::fake(InboundMailAttachment::DISK);
        Storage::disk(InboundMailAttachment::DISK)->put('inbound/1/pagina.html', '<script>alert(1)</script>');
        $attachment = InboundMail::factory()->create()->attachments()->create([
            'mailtrap_attachment_id' => 'a1', 'filename' => 'pagina.html', 'content_type' => 'text/html', 'path' => 'inbound/1/pagina.html',
        ]);
        $missing = $attachment->inboundMail->attachments()->create(['mailtrap_attachment_id' => 'a2', 'filename' => 'x.pdf']);

        $this->get('/plataforma/correos/adjuntos/'.$attachment->getKey())->assertRedirect('/plataforma/login');

        $this->asOperator();
        $response = $this->get('/plataforma/correos/adjuntos/'.$attachment->getKey())->assertOk();

        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->get('/plataforma/correos/adjuntos/'.$missing->getKey())->assertNotFound();
    }

    public function test_reply_all_is_prefilled_and_sending_returns_to_the_thread(): void
    {
        $thread = MailThread::factory()->create();
        $source = InboundMail::factory()->create([
            'mail_thread_id' => $thread->getKey(), 'from_address' => 'ana@gmail.com', 'subject' => 'Precio',
            'to' => ['ventas@apartar.app', 'socio@example.com'], 'cc' => ['rosa@example.com'],
        ]);
        $operator = $this->asOperator();

        $this->get('/plataforma/correos/redactar?responder='.$source->getKey().'&modo=responder-todos')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/mail/Compose')
            ->where('mode', 'responder-todos')
            ->where('defaults.to', 'ana@gmail.com')
            ->where('defaults.cc', 'socio@example.com, rosa@example.com')
            ->where('defaults.subject', 'Re: Precio')
            ->where('from', 'info@apartar.app'));

        $this->post('/plataforma/correos/enviar', [
            'mode' => 'responder-todos', 'source' => $source->getKey(), 'to' => 'Ana <ana@gmail.com>', 'cc' => 'socio@example.com, rosa@example.com',
            'subject' => 'Re: Precio', 'body' => '<p>Son 10 $ al mes.</p>',
        ])->assertRedirect('/plataforma/correos/bandeja/'.$thread->getKey());

        $sent = SentMail::query()->where('platform_user_id', $operator->getKey())->sole();
        $this->assertSame(['socio@example.com', 'rosa@example.com'], $sent->cc);
        $this->assertSame('info@apartar.app', $this->lastEmail()->getFrom()[0]->getAddress());
    }

    public function test_a_bad_address_or_an_empty_body_is_sent_back_with_an_error(): void
    {
        $this->asOperator();

        $this->post('/plataforma/correos/enviar', ['mode' => 'nuevo', 'to' => 'ana@gmail.com, no-es-correo', 'subject' => 'Hola', 'body' => '<p>Hola</p>'])
            ->assertSessionHasErrors('to');
        $this->post('/plataforma/correos/enviar', ['mode' => 'nuevo', 'to' => 'ana@gmail.com', 'subject' => 'Hola', 'body' => '<p> </p>'])
            ->assertSessionHasErrors('body');

        $this->assertSame([], $this->sentEmails());
    }

    public function test_the_dashboard_carries_the_mail_summary_and_the_nav_the_unread_count(): void
    {
        MailThread::factory()->create(['unread_count' => 2]);
        $this->asOperator();

        $this->get('/plataforma')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('mail.sent')
            ->where('mail.unreadThreads', 1)
            ->where('platformOperator.unreadMail', 1));
    }
}
