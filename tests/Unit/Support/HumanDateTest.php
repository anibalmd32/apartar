<?php

declare(strict_types=1);

use App\Support\HumanDate;
use Carbon\CarbonImmutable;

/**
 * @return array{timeZone: string, moments: list<array{value: string, short: string, long: string, numeric: string, dayMonth: string, time: string, dateTime: string}>}
 */
function humanDateCases(): array
{
    $path = dirname(__DIR__, 3).'/resources/js/lib/dates.cases.json';

    /** @var array{timeZone: string, moments: list<array{value: string, short: string, long: string, numeric: string, dayMonth: string, time: string, dateTime: string}>} */
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function plainSpaces(string $text): string
{
    return str_replace("\u{00A0}", ' ', $text);
}

it('enseña cada fecha de la tabla igual que el navegador', function (): void {
    $cases = humanDateCases();

    foreach ($cases['moments'] as $case) {
        $at = strlen($case['value']) === 10
            ? CarbonImmutable::parse($case['value'], $cases['timeZone'])
            : CarbonImmutable::parse($case['value'])->setTimezone($cases['timeZone']);

        expect(HumanDate::short($at))->toBe($case['short'])
            ->and(HumanDate::long($at))->toBe($case['long'])
            ->and(HumanDate::numeric($at))->toBe($case['numeric'])
            ->and(HumanDate::dayMonth($at))->toBe($case['dayMonth'])
            ->and(plainSpaces(HumanDate::time($at)))->toBe($case['time'])
            ->and(plainSpaces(HumanDate::dateTime($at)))->toBe($case['dateTime']);
    }
});

it('no parte la hora entre líneas', function (): void {
    expect(HumanDate::time(CarbonImmutable::parse('2026-10-12 21:45', 'America/Caracas')))->toBe("9:45\u{00A0}p.\u{00A0}m.");
});

it('junta la fecha larga con la hora', function (): void {
    expect(plainSpaces(HumanDate::longDateTime(CarbonImmutable::parse('2026-10-12 09:05', 'America/Caracas'))))
        ->toBe('12 de octubre de 2026, 9:05 a. m.');
});
