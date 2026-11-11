<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MonitorAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ConcealMonitorHost
{
    public function __construct(private readonly SessionManager $sessions) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! MonitorAccess::onHost($request)) {
            return $next($request);
        }

        if (! $this->servesMonitor($request)) {
            return MonitorAccess::nothing();
        }

        config(['session.domain' => null]);
        $this->sessions->driver()->setName(MonitorAccess::SESSION_COOKIE);

        return $next($request);
    }

    private function servesMonitor(Request $request): bool
    {
        if (Str::startsWith((string) $request->route()?->getName(), 'monitor.')) {
            return true;
        }

        $segment = (string) $request->segment(1);
        $panels = [config('pulse.path', 'pulse'), config('telescope.path', 'telescope'), config('log-viewer.route_path', 'logs')];

        return in_array($segment, array_map(strval(...), $panels), true) || Str::startsWith($segment, 'livewire');
    }
}
