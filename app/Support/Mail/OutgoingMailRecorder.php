<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Domain\Mail\MailCategory;
use App\Domain\Mail\MailStatus;
use App\Domain\Mail\RecipientProvider;
use App\Models\SentMail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

final class OutgoingMailRecorder
{
    public const UUID_HEADER = 'X-Apartar-Mail-Id';

    public const CUSTOM_VARIABLES_HEADER = 'X-MT-Custom-Variables';

    public const CATEGORY_HEADER = 'X-MT-Category';

    public const VARIABLE = 'apartar_mail_id';

    public function __construct(private readonly OutgoingMailContext $context) {}

    /**
     * @param  array<mixed>  $data
     */
    public function prepare(Message $message, array $data): SentMail
    {
        $headers = $message->getHeaders();
        $given = $headers->get(self::UUID_HEADER)?->getBodyAsString();
        $uuid = is_string($given) && Str::isUuid($given) ? strtolower($given) : (string) Str::uuid();
        $headers->remove(self::UUID_HEADER);
        $category = MailCategory::fromMessageData($data);

        if (! $headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', $uuid.'@'.self::domain());
        }

        $headers->remove(self::CUSTOM_VARIABLES_HEADER);
        $headers->remove(self::CATEGORY_HEADER);
        $headers->addTextHeader(self::CUSTOM_VARIABLES_HEADER, (string) json_encode([self::VARIABLE => $uuid]));
        $headers->addTextHeader(self::CATEGORY_HEADER, $category);

        $email = $message instanceof Email ? $message : null;
        $from = $email?->getFrom()[0] ?? null;
        $to = self::addresses($email?->getTo() ?? []);

        return SentMail::query()->create([
            'uuid' => $uuid,
            'message_id' => self::messageId($message),
            'from_address' => $from?->getAddress() ?? (string) config('mail.from.address'),
            'from_name' => $from?->getName() ?: null,
            'to' => $to,
            'cc' => self::addresses($email?->getCc() ?? []),
            'bcc' => self::addresses($email?->getBcc() ?? []),
            'recipient_domain' => RecipientProvider::domainOf($to[0] ?? ''),
            'subject' => mb_substr((string) $email?->getSubject(), 0, 500),
            'html_body' => self::body($email?->getHtmlBody()),
            'text_body' => self::body($email?->getTextBody()),
            'headers' => self::headersOf($message),
            'category' => $category,
            'status' => MailStatus::Sending,
            'target_organization_id' => $this->context->organizationFor($data),
        ]);
    }

    public function markSent(Message $message): void
    {
        $uuid = self::uuidOf($message);

        if ($uuid === null) {
            return;
        }

        SentMail::query()->where('uuid', $uuid)->where('status', MailStatus::Sending->value)->update([
            'status' => MailStatus::Sent->value,
            'sent_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function uuidOf(Message $message): ?string
    {
        $raw = $message->getHeaders()->get(self::CUSTOM_VARIABLES_HEADER)?->getBodyAsString();
        $variables = is_string($raw) ? json_decode($raw, true) : null;
        $uuid = is_array($variables) ? ($variables[self::VARIABLE] ?? null) : null;

        return is_string($uuid) && Str::isUuid($uuid) ? $uuid : null;
    }

    public static function domain(): string
    {
        $domain = RecipientProvider::domainOf((string) config('mail.from.address'));

        return $domain === '' ? 'apartar.app' : $domain;
    }

    private static function messageId(Message $message): ?string
    {
        $header = $message->getHeaders()->get('Message-ID');

        return $header === null ? null : trim($header->getBodyAsString(), '<> ');
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address): string => strtolower($address->getAddress()), $addresses));
    }

    private static function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);
            rewind($body);

            return $contents === false ? null : $contents;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * @return array<string, string>
     */
    private static function headersOf(Message $message): array
    {
        $headers = [];

        foreach ($message->getHeaders()->all() as $header) {
            $headers[$header->getName()] = mb_substr($header->getBodyAsString(), 0, 2000);
        }

        return $headers;
    }
}
