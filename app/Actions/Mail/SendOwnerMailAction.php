<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Domain\Mail\ComposeMode;
use App\Domain\Mail\MailBody;
use App\Domain\Mail\MailStatus;
use App\Mail\OwnerMail;
use App\Models\InboundMail;
use App\Models\MailThread;
use App\Models\PlatformUser;
use App\Models\SentMail;
use App\Support\Auditing\PlatformAudit;
use App\Support\Mail\MailThreads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SendOwnerMailAction
{
    public const MAX_RECIPIENTS = 20;

    public function __construct(private readonly MailThreads $threads) {}

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function handle(PlatformUser $operator, ComposeMode $mode, ?InboundMail $source, array $to, array $cc, string $subject, string $html): SentMail
    {
        $body = MailBody::sanitize($html);
        $subject = trim($subject);

        if ($to === []) {
            throw new InvalidArgumentException('Indica al menos un destinatario.');
        }

        if (count($to) + count($cc) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException(sprintf('Como máximo %d destinatarios por correo.', self::MAX_RECIPIENTS));
        }

        if ($body === '') {
            throw new InvalidArgumentException('Escribe el mensaje.');
        }

        if ($mode->needsSource() !== ($source !== null)) {
            throw new InvalidArgumentException('Falta el correo al que se responde.');
        }

        $uuid = (string) Str::uuid();
        $mail = $this->mailable($uuid, $mode, $source, $subject === '' ? '(sin asunto)' : $subject, $body);

        try {
            Mail::to($to)->cc($cc)->send($mail);
        } catch (Throwable $exception) {
            SentMail::query()->where('uuid', $uuid)->update(['status' => MailStatus::Failed->value, 'updated_at' => now()]);
            report($exception);

            throw new RuntimeException('No se pudo enviar el correo. Inténtalo de nuevo en unos minutos.', previous: $exception);
        }

        $sent = SentMail::query()->where('uuid', $uuid)->firstOrFail();
        $thread = $source->thread ?? $this->threads->resolve([], null, $sent->subject, $to[0], CarbonImmutable::now());

        $sent->forceFill(['platform_user_id' => $operator->getKey(), 'mail_thread_id' => $thread->getKey()])->save();
        $this->markRead($thread);
        $this->threads->touch($thread, MailBody::plainText($body));

        PlatformAudit::record(PlatformAudit::MAIL_SENT, sprintf('%s a %s: %s', $mode->label(), implode(', ', $to), $sent->subject), $operator, properties: [
            'sent_mail' => $uuid,
            'mode' => $mode->value,
            'cc' => $cc,
        ]);

        return $sent;
    }

    private function mailable(string $uuid, ComposeMode $mode, ?InboundMail $source, string $subject, string $body): OwnerMail
    {
        if ($source === null) {
            return new OwnerMail($uuid, $subject, $body, MailBody::plainText($body));
        }

        $intro = sprintf('El %s, %s escribió:', $source->received_at->locale('es')->isoFormat('LLL'), $source->from_name === null ? $source->from_address : $source->from_name.' <'.$source->from_address.'>');
        $quoted = MailBody::textOf($source->text_body, $source->html_body);
        $html = $body.MailBody::quoteHtml($intro, $quoted);
        $text = MailBody::plainText($body)."\n\n".MailBody::quoteText($intro, $quoted);

        if (! $mode->threadsWithSource()) {
            return new OwnerMail($uuid, $subject, $html, $text, forwarded: $source->attachments()->get()->all());
        }

        $references = array_values(array_unique(array_filter([...array_map(fn (string $id): string => trim($id, '<> '), $source->references), $source->message_id])));

        return new OwnerMail($uuid, $subject, $html, $text, $source->message_id, $references);
    }

    private function markRead(MailThread $thread): void
    {
        $thread->inboundMails()->whereNull('read_at')->update(['read_at' => now()]);
    }
}
