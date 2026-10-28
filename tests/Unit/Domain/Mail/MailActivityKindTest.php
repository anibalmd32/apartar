<?php

declare(strict_types=1);

use App\Domain\Mail\MailActivityKind;

it('fija los nombres que espera el panel en vivo', function (): void {
    expect(array_map(fn (MailActivityKind $kind): string => $kind->value, MailActivityKind::cases()))
        ->toBe(['sent', 'event', 'inbound']);
});
