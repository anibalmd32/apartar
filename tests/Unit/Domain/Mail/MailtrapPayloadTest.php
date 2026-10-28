<?php

declare(strict_types=1);

use App\Domain\Mail\MailtrapPayload;

it('lee el formato JSON con su lista de eventos', function (): void {
    $body = '{"events":[{"event":"delivery","event_id":"a"},{"event":"open","event_id":"b"}]}';

    expect(MailtrapPayload::events($body))->toBe([
        ['event' => 'delivery', 'event_id' => 'a'],
        ['event' => 'open', 'event_id' => 'b'],
    ]);
});

it('lee el formato JSON Lines, una línea por evento', function (): void {
    $body = "{\"event\":\"delivery\",\"event_id\":\"a\"}\n\n{\"event\":\"click\",\"event_id\":\"b\"}\n";

    expect(MailtrapPayload::events($body))->toBe([
        ['event' => 'delivery', 'event_id' => 'a'],
        ['event' => 'click', 'event_id' => 'b'],
    ]);
});

it('acepta un evento suelto y descarta lo que no es un objeto', function (): void {
    expect(MailtrapPayload::events('{"event":"open","event_id":"x"}'))->toBe([['event' => 'open', 'event_id' => 'x']])
        ->and(MailtrapPayload::events('{"events":[1,"x",{"event":"open"}]}'))->toBe([['event' => 'open']])
        ->and(MailtrapPayload::events('{"events":[]}'))->toBe([]);
});

it('no admite un cuerpo vacío o roto', function (string $body): void {
    expect(MailtrapPayload::events($body))->toBeNull();
})->with(['', '   ', 'no es json', '{"otra":"cosa"}', "{\"event\":\"a\"}\nroto", '{"events":"x"}']);

it('corta los lotes demasiado grandes', function (): void {
    $events = array_fill(0, MailtrapPayload::MAX_EVENTS + 5, ['event' => 'open']);

    expect(MailtrapPayload::events((string) json_encode(['events' => $events])))->toHaveCount(MailtrapPayload::MAX_EVENTS);
});
