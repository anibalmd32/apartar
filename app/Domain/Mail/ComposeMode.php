<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Models\InboundMail;

enum ComposeMode: string
{
    case New = 'nuevo';
    case Reply = 'responder';
    case ReplyAll = 'responder-todos';
    case Forward = 'reenviar';

    public function needsSource(): bool
    {
        return $this !== self::New;
    }

    public function threadsWithSource(): bool
    {
        return $this === self::Reply || $this === self::ReplyAll;
    }

    public function subject(string $original): string
    {
        return match ($this) {
            self::New => $original,
            self::Reply, self::ReplyAll => MailSubject::reply($original),
            self::Forward => MailSubject::forward($original),
        };
    }

    /**
     * @return array{to: list<string>, cc: list<string>}
     */
    public function recipients(InboundMail $source, string $ownDomain): array
    {
        $replyTo = strtolower($source->replyAddress());

        if ($this === self::Reply) {
            return ['to' => [$replyTo], 'cc' => []];
        }

        if ($this !== self::ReplyAll) {
            return ['to' => [], 'cc' => []];
        }

        $others = array_filter(
            [...$source->to, ...$source->cc],
            fn (string $email): bool => $email !== $replyTo && ! str_ends_with(strtolower($email), '@'.strtolower($ownDomain)),
        );

        return ['to' => [$replyTo], 'cc' => array_values(array_unique($others))];
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'Correo nuevo',
            self::Reply => 'Responder',
            self::ReplyAll => 'Responder a todos',
            self::Forward => 'Reenviar',
        };
    }
}
