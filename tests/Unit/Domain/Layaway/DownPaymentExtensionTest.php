<?php

declare(strict_types=1);

use App\Domain\Layaway\DownPaymentExtension;

it('solo cuenta como concedida la prórroga concedida', function (DownPaymentExtension $outcome, bool $granted): void {
    expect($outcome->wasGranted())->toBe($granted);
})->with([
    'concedida' => [DownPaymentExtension::Granted, true],
    'ventana abierta' => [DownPaymentExtension::WindowStillOpen, false],
    'ya prorrogado' => [DownPaymentExtension::AlreadyExtended, false],
    'no espera la inicial' => [DownPaymentExtension::NotAwaitingDownPayment, false],
]);

it('tiene exactamente esos cuatro casos', function (): void {
    expect(array_map(fn (DownPaymentExtension $case): string => $case->value, DownPaymentExtension::cases()))
        ->toBe(['granted', 'window_still_open', 'already_extended', 'not_awaiting_down_payment']);
});
