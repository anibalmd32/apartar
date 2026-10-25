<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ExpiredInertiaSession
{
    private const EXPIRED = 419;

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if ($response->getStatusCode() !== self::EXPIRED || ! $request->hasHeader('X-Inertia')) {
            return $response;
        }

        return Inertia::location(route('login'));
    }
}
