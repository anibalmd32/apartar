<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\MailtrapWebhookController;
use App\Http\Controllers\Api\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::middleware('outside-tenancy')->group(function (): void {
    Route::get('tasa', ExchangeRateController::class)->name('api.rate');
    Route::post('telemetry', TelemetryController::class)->middleware('throttle:telemetria')->name('api.telemetry');
    Route::post('webhooks/mailtrap/envios', [MailtrapWebhookController::class, 'sending'])->middleware('throttle:webhooks')->name('api.mailtrap.sending');
});
