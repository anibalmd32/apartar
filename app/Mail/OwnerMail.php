<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\InboundMailAttachment;
use App\Support\Mail\OutgoingMailRecorder;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Email;

final class OwnerMail extends Mailable
{
    /**
     * @param  list<string>  $references
     * @param  list<InboundMailAttachment>  $forwarded
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $mailSubject,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly ?string $inReplyTo = null,
        public readonly array $references = [],
        public readonly array $forwarded = [],
    ) {
        $this->withSymfonyMessage(fn (Email $message): Email => $message->text($this->textBody));
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.owner.address'), (string) config('mail.owner.name')),
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->uuid.'@'.OutgoingMailRecorder::domain(),
            references: $this->references,
            text: array_filter([
                OutgoingMailRecorder::UUID_HEADER => $this->uuid,
                'In-Reply-To' => $this->inReplyTo === null ? null : '<'.$this->inReplyTo.'>',
            ], fn (?string $value): bool => $value !== null),
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1f2937">'.$this->htmlBody.'</div>');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_values(array_map(
            fn (InboundMailAttachment $file): Attachment => Attachment::fromStorageDisk(InboundMailAttachment::DISK, (string) $file->path)
                ->as($file->filename)
                ->withMime($file->content_type),
            array_filter($this->forwarded, fn (InboundMailAttachment $file): bool => $file->isStored()),
        ));
    }
}
