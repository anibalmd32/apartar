<?php

declare(strict_types=1);

namespace App\Livewire\Monitor;

use App\Queries\Platform\SystemHealthQuery;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

#[Lazy]
final class PlatformHealth extends Card
{
    public const CACHE_KEY = 'monitor:platform-health';

    public const CACHE_SECONDS = 30;

    public function render(SystemHealthQuery $health): Renderable
    {
        return View::make('livewire.monitor.platform-health', [
            'health' => Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $health->compute()),
        ]);
    }
}
