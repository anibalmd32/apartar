<?php

declare(strict_types=1);

namespace App\Domain\Mail;

final class MailtrapPayload
{
    public const MAX_EVENTS = 1000;

    /**
     * @return list<array<string, mixed>>|null
     */
    public static function events(string $body): ?array
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return self::fromDocument($decoded);
        }

        $events = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $event = json_decode($line, true);

            if (! is_array($event) || array_is_list($event)) {
                return null;
            }

            $events[] = $event;
        }

        return $events === [] ? null : array_slice($events, 0, self::MAX_EVENTS);
    }

    /**
     * @param  array<mixed>  $document
     * @return list<array<string, mixed>>|null
     */
    private static function fromDocument(array $document): ?array
    {
        $events = array_is_list($document) ? $document : ($document['events'] ?? (isset($document['event']) ? [$document] : null));

        if (! is_array($events)) {
            return null;
        }

        $valid = [];

        foreach ($events as $event) {
            if (is_array($event) && ! array_is_list($event)) {
                $valid[] = $event;
            }
        }

        return array_slice($valid, 0, self::MAX_EVENTS);
    }
}
